<?php

namespace Aurora\Tests\Feature;

use Aurora\Forms\Fields\Text;
use Aurora\Forms\FieldType;
use Aurora\Models\Form;
use Aurora\Models\FormSubmission;
use Aurora\Models\Page;
use Aurora\Support\ApiContent;
use Aurora\Support\FormFields;
use Aurora\Tests\TestCase;
use Closure;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Field types a website or package adds to forms (FormFields::register()): set up in the form
 * builder, checked before anything is kept, kept or left out as the type says, and drawn on
 * the website by the type itself with its script loaded once.
 */
class FormFieldTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FormFields::register([SecretWord::class]);
    }

    protected function tearDown(): void
    {
        FormFields::forget(SecretWord::type());

        parent::tearDown();
    }

    protected function form(): Form
    {
        return Form::factory()->create(['fields' => [
            ['type' => 'text', 'data' => ['name' => 'name', 'label' => 'Name', 'required' => true]],
            ['type' => 'secret_word', 'data' => ['name' => 'check', 'label' => 'Prove you are human', 'word' => 'aurora', 'help' => 'Type the word.']],
            ['type' => 'secret_word', 'data' => ['name' => 'check_again', 'label' => 'Once more', 'word' => 'aurora']],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function answers(array $overrides = []): array
    {
        return ['name' => 'Kari', 'check' => 'aurora', 'check_again' => 'aurora', '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp), ...$overrides];
    }

    public function test_a_registered_type_is_offered_in_the_form_builder_after_auroras_own(): void
    {
        $keys = array_keys(FormFields::all());

        $this->assertSame(['text', 'email', 'phone', 'textarea', 'select', 'radio', 'checkboxes', 'date', 'file', 'consent', 'secret_word'], $keys);
        $this->assertContains('secret_word', array_map(fn ($block): string => $block->getName(), FormFields::builderBlocks()));
        $this->assertFalse(FormFields::isBuiltIn('secret_word'));
        $this->assertTrue(FormFields::isBuiltIn('email'));
    }

    public function test_its_rules_run_before_anything_is_kept_and_its_answer_is_left_out_as_it_says(): void
    {
        $form = $this->form();

        $this->post(route('forms.submit', $form), $this->answers(['check' => 'wrong']))->assertSessionHasErrors('check', null, 'form_'.$form->id);
        $this->assertSame(0, FormSubmission::query()->count());

        $this->post(route('forms.submit', $form), $this->answers())->assertSessionHasNoErrors();

        // Kept: the name. Not kept: the check, as SecretWord::stores() says.
        $this->assertSame(['name' => ['label' => 'Name', 'value' => 'Kari']], FormSubmission::query()->sole()->data);
    }

    public function test_the_website_draws_it_with_the_types_own_control_and_its_script_once(): void
    {
        $form = $this->form();
        Page::factory()->homepage()->create(['blocks' => [['id' => 'f', 'type' => 'form', 'data' => ['form_id' => $form->id]]]]);

        $page = $this->get('/')->assertOk();

        $page->assertSee('data-secret-word', false)
            ->assertSee('name="check"', false)
            // Unlabelled, as it says (labelled()), and its script only once for both fields.
            ->assertDontSee('Prove you are human');
        $this->assertSame(1, substr_count($page->getContent(), 'https://example.com/secret-word.js'));
    }

    public function test_the_apis_show_only_the_settings_it_allows(): void
    {
        $field = collect(ApiContent::form($this->form()->id)['fields'])->firstWhere('name', 'check');

        $this->assertSame('Type the word.', $field['help']);
        $this->assertArrayNotHasKey('word', $field);
    }

    public function test_a_field_of_a_type_that_is_gone_is_taken_as_short_text(): void
    {
        FormFields::forget('secret_word');

        $this->assertSame(Text::class, FormFields::find('secret_word'));
        $this->assertSame(['nullable', 'string', 'max:255'], FormFields::rules(['type' => 'secret_word', 'name' => 'check', 'label' => 'Check', 'required' => false, 'data' => []]));
    }

    public function test_only_field_types_can_be_registered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormFields::register([Form::class]);
    }
}

/**
 * A field a package might add, like a captcha: the visitor types a word the editor chose.
 */
class SecretWord extends FieldType
{
    public static function type(): string
    {
        return 'secret_word';
    }

    public static function label(): string
    {
        return 'Secret word';
    }

    public static function settings(): array
    {
        return [TextInput::make('word')->required()];
    }

    public static function rules(array $field): array
    {
        return ['required', function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if ($value !== ($field['data']['word'] ?? null)) {
                $fail('That isn’t the word.');
            }
        }];
    }

    public static function stores(): bool
    {
        return false;
    }

    public static function publicData(): array
    {
        return ['help'];
    }

    public static function labelled(): bool
    {
        return false;
    }

    public static function render(array $field, string $id, string $attributes): string
    {
        return '<input type="text" data-secret-word '.$attributes.'>';
    }

    public static function scripts(): array
    {
        return ['https://example.com/secret-word.js'];
    }
}
