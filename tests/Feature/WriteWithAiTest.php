<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Filament\Resources\LanguageLines\Pages\ManageLanguageLines;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Form;
use Aurora\Models\LanguageLine;
use Aurora\Models\Page;
use Aurora\Models\Role;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\AiWriter;
use Aurora\Support\AssetLibrary;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\Copilot;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

class WriteWithAiTest extends TestCase
{
    use RefreshDatabase;

    protected function switchOnTheAssistant(): void
    {
        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]);
        Copilot::storeApiKey('sk-ant-test-1234');
    }

    protected function page(): Page
    {
        return Page::factory()->create([
            'title' => 'Workshops',
            'blocks' => BlockTreeBuilder::flatten([['type' => 'heading', 'data' => ['text' => 'Spring workshops in Bergen', 'level' => 'h2']]]),
        ]);
    }

    public function test_the_sparkle_shows_only_when_the_assistant_is_on_and_allowed(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = $this->page();
        $sparkle = TestAction::make('writeWithAi')->schemaComponent('title', schema: 'form');

        Livewire::actingAs($editor)->test(EditPage::class, ['record' => $page->getRouteKey()])->assertActionDoesNotExist($sparkle);

        $this->switchOnTheAssistant();

        Livewire::actingAs($editor)->test(EditPage::class, ['record' => $page->getRouteKey()])->assertActionVisible($sparkle);

        // Without "Use the AI assistant", no sparkle.
        Role::findByName('Editor')->revokePermissionTo('use-assistant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($editor->fresh())->test(EditPage::class, ['record' => $page->getRouteKey()])->assertActionDoesNotExist($sparkle);
    }

    public function test_it_writes_the_field_from_the_page_in_the_websites_language(): void
    {
        $this->withEnglishAndNorwegian();
        $this->switchOnTheAssistant();
        AiWriter::fake(['“Spring workshops: design sprints in Bergen”']);
        $page = $this->page();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callAction(TestAction::make('writeWithAi')->schemaComponent('meta_title', schema: 'form'), ['instruction' => 'mention Bergen'])
            ->assertSchemaStateSet(['meta_title' => 'Spring workshops: design sprints in Bergen'], 'form');

        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('SEO title')
            && $prompt->contains('mention Bergen')
            && $prompt->contains('Spring workshops in Bergen')
            && $prompt->contains('50-60 characters')
            && $prompt->contains('Write in English')
            && $prompt->attachments->isEmpty());
    }

    public function test_alt_text_is_written_from_the_image_itself_on_click_without_a_dialog(): void
    {
        Storage::fake('public');
        $this->switchOnTheAssistant();
        AiWriter::fake(['A green ridge in morning mist above a fjord.']);
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('ridge.jpg', 400, 300));

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditAsset::class, ['record' => $asset->getRouteKey()])
            ->mountAction(TestAction::make('writeWithAi')->schemaComponent('alt', schema: 'form'))
            ->assertActionNotMounted()
            ->assertSchemaStateSet(['alt' => 'A green ridge in morning mist above a fjord.'], 'form')
            // The caption keeps the dialog, for an instruction.
            ->mountAction(TestAction::make('writeWithAi')->schemaComponent('caption', schema: 'form'))
            ->assertActionMounted(TestAction::make('writeWithAi')->schemaComponent('caption', schema: 'form'));

        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1 && $prompt->contains('Alt text'));
    }

    public function test_writing_without_a_language_uses_the_sites_language(): void
    {
        $this->withEnglishAndNorwegian();
        Site::default()->update(['default_locale' => 'nb']);
        AiWriter::fake(['Hei']);

        $this->assertSame('Hei', AiWriter::write('Title'));

        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Write in Norsk bokmål, the website\'s language'));
    }

    public function test_a_translation_is_written_into_its_table_cell_in_that_language(): void
    {
        $this->withEnglishAndNorwegian();
        $this->switchOnTheAssistant();
        AiWriter::fake(['Lagre utkastet']);
        $line = LanguageLine::query()->create(['group' => '*', 'key' => 'Save the draft', 'text' => ['en' => 'Save the draft'], 'imported' => ['en' => 'Save the draft']]);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ManageLanguageLines::class, ['locale' => 'nb'])
            ->assertSeeHtml('cms-ai-cell-button')
            ->mountAction('writeColumnWithAi', arguments: ['column' => 'translation', 'record' => $line->id])
            ->assertActionNotMounted();

        $this->assertSame('Lagre utkastet', $line->fresh()->text['nb']);

        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Write in Norsk bokmål.')
            && $prompt->contains('Save the draft')
            && $prompt->contains('keep :placeholders'));
    }

    public function test_a_form_fields_text_is_translated_on_click(): void
    {
        $this->withEnglishAndNorwegian();
        $this->switchOnTheAssistant();
        AiWriter::fake(['Navnet ditt']);
        $form = Form::factory()->create();
        $label = TestAction::make('writeWithAi')->schemaComponent('field_translations.nb.name.label', schema: 'form');

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditForm::class, ['record' => $form->getRouteKey()])
            ->call('setActiveLocale', 'nb')
            ->mountAction($label)
            ->assertActionNotMounted()
            ->assertSchemaStateSet(['field_translations.nb.name.label' => 'Navnet ditt'], 'form');

        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Write in Norsk bokmål.') && $prompt->contains('"Name"'));
    }
}
