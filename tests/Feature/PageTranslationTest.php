<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Models\Form;
use Aurora\Models\Locale;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\AiTranslator;
use Aurora\Support\AssetLibrary;
use Aurora\Support\BlockTranslations;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\ContentLocale;
use Aurora\Support\Copilot;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

class PageTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withEnglishAndNorwegian();
    }

    protected function page(): Page
    {
        return Page::factory()->create([
            'title' => 'About us',
            'status' => PageStatus::Published,
            'published_at' => now()->subDay(),
            'blocks' => BlockTreeBuilder::flatten([
                ['type' => 'heading', 'data' => ['text' => 'Small team, big craft', 'level' => 'h2']],
                ['type' => 'heading', 'data' => ['text' => 'Since 2014', 'level' => 'h3']],
            ]),
        ]);
    }

    public function test_each_language_has_its_own_title_and_address_and_falls_back_to_the_default(): void
    {
        $page = $this->page();
        $page->setTranslation('title', 'nb', 'Om oss')->save();

        $this->assertSame(['en' => 'about-us', 'nb' => 'om-oss'], $page->fresh()->getTranslations('slug'));
        $this->assertSame('/about-us', $page->path('en'));
        $this->assertSame('/nb/om-oss', $page->fresh()->path('nb'));

        $this->get('/nb/om-oss')->assertOk()->assertSee('<html lang="nb"', false)->assertSee('Om oss · ', false);
        // No Norwegian summary yet: the English one shows.
        $this->assertSame($page->excerpt, ContentLocale::using('nb', fn () => $page->fresh()->excerpt));
    }

    public function test_a_page_is_translated_in_the_visual_editor_without_a_second_block_set(): void
    {
        $page = $this->page();
        [$first, $second] = collect($page->blocks)->pluck('id')->all();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(DesignPage::class, ['record' => $page->getRouteKey(), 'language' => 'nb'])
            ->assertSee('2 blocks not translated')
            ->call('setBlockField', $first, 'text', 'Lite team, stort håndverk')
            ->assertSee('1 block not translated')
            ->call('setBlockField', $first, 'level', 'h1')
            ->call('removeBlock', $second)
            ->assertNotified('The layout is the same in every language')
            ->call('save');

        // A live page: the changes wait until they're published.
        $page->refresh()->publishWorkingCopy();
        $page->refresh();

        $this->assertCount(2, $page->blocks);
        $this->assertSame('Small team, big craft', $page->blocks[0]['data']['text']);
        $this->assertSame('h2', $page->blocks[0]['data']['level']);
        $this->assertSame([$first => ['text' => 'Lite team, stort håndverk']], $page->block_translations['nb']);

        $this->get($page->url('nb'))->assertSee('Lite team, stort håndverk')->assertSee('Since 2014');
        $this->get($page->url('en'))->assertSee('Small team, big craft')->assertDontSee('Lite team');
    }

    public function test_the_whole_page_is_translated_with_ai_for_review(): void
    {
        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]);
        Copilot::storeApiKey('sk-ant-test-1234');
        $page = $this->page();
        [$first, $second] = collect($page->blocks)->pluck('id')->all();

        AiTranslator::fake(fn (string $prompt): string => json_encode(
            collect(json_decode(Str::after($prompt, ':'), true))->map(fn (string $text): string => 'NB '.$text)->all(),
        ));

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(DesignPage::class, ['record' => $page->getRouteKey(), 'language' => 'nb'])
            ->assertActionVisible('translateWithAi')
            ->callAction('translateWithAi', ['scope' => 'missing', 'page_fields' => true])
            ->assertSet('isDirty', true)
            ->assertSee('Fully translated')
            ->call('save');

        // A live page: the changes wait until they're published.
        $page->refresh()->publishWorkingCopy();
        $page->refresh();

        $this->assertSame([$first => ['text' => 'NB Small team, big craft'], $second => ['text' => 'NB Since 2014']], $page->block_translations['nb']);
        $this->assertSame('NB About us', $page->getTranslation('title', 'nb'));
        $this->assertSame('nb-about-us', $page->getTranslation('slug', 'nb'));
        $this->assertSame('About us', $page->getTranslation('title', 'en'));

        AiTranslator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('into Norsk bokmål'));
    }

    public function test_editing_text_in_the_inspector_keeps_the_blocks_shared_fields(): void
    {
        $page = Page::factory()->create(['blocks' => BlockTreeBuilder::flatten([
            ['type' => 'hero', 'data' => ['layout' => 'split', 'headline' => 'A ferry app', 'subheadline' => 'For a million travellers']],
        ])]);
        $hero = $page->blocks[0]['id'];

        $editor = Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(DesignPage::class, ['record' => $page->getRouteKey(), 'language' => 'nb'])
            ->call('selectBlock', $hero)
            ->set('blockData.headline', 'En ferjeapp')
            ->call('save');

        $hero = collect($editor->get('blocks'))->firstWhere('id', $hero);

        $this->assertSame('split', $hero['data']['layout']);
        $this->assertSame('En ferjeapp', $hero['data']['headline']);
        $this->assertSame(['headline' => 'En ferjeapp'], $page->fresh()->block_translations['nb'][$hero['id']]);
    }

    public function test_a_new_page_starts_in_every_language(): void
    {
        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(CreatePage::class)
            ->assertSchemaStateSet(['locales' => ['en', 'nb']], 'form');
    }

    public function test_a_page_can_leave_languages_out(): void
    {
        $page = $this->page();
        $page->setTranslation('title', 'nb', 'Om oss')->forceFill(['locales' => ['en']])->save();

        $this->assertSame(['en'], $page->availableLocales());
        $this->assertFalse($page->isTranslatable());

        $this->get('/nb/om-oss')->assertNotFound();
        $this->get('/about-us')->assertOk()->assertDontSee('hreflang="nb"', false);
        // Links to it in Norwegian lead to the page in the default site language.
        $this->assertSame('/about-us', $page->path('nb'));

        $admin = $this->userWithRole(User::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(DesignPage::class, ['record' => $page->getRouteKey(), 'language' => 'nb'])
            ->assertSet('language', null)
            ->assertDontSeeHtml('cms-editor-language');

        Livewire::actingAs($admin)
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->assertSchemaStateSet(['locales' => ['en']], 'form')
            ->fillForm(['locales' => []])
            ->call('save')
            ->assertHasFormErrors(['locales'])
            ->fillForm(['locales' => ['en', 'nb']])
            ->call('save')
            ->assertHasNoFormErrors();

        // A live page: the changes wait until they're published.
        $page->fresh()->publishWorkingCopy();
        $this->assertSame(['en', 'nb'], $page->fresh()->locales);
        $this->assertTrue($page->fresh()->isTranslatable());
        $this->get('/nb/om-oss')->assertOk();
    }

    public function test_changing_the_default_language_keeps_each_languages_text(): void
    {
        $page = $this->page();
        $id = $page->blocks[0]['id'];
        $page->setTranslation('title', 'nb', 'Om oss')->forceFill([
            'block_translations' => ['nb' => [$id => ['text' => 'Lite team, stort håndverk']]],
        ])->save();

        $form = Form::query()->create([
            'name' => 'Contact',
            'submit_label' => 'Send',
            'fields' => [
                ['type' => 'text', 'data' => ['label' => 'Your name', 'name' => 'name', 'required' => true]],
            ],
            'field_translations' => ['nb' => ['name' => ['label' => 'Navnet ditt']]],
        ]);

        // The website's language is the site's own (Settings › Sites), not the CMS language.
        Site::default()->update(['default_locale' => 'nb']);

        $page = $page->fresh();

        $this->assertSame('en', Locale::defaultCode());
        $this->assertSame('nb', ContentLocale::default());
        $this->assertSame('en', $page->primaryLocale());
        $this->assertSame('Small team, big craft', $page->localizedBlocks('en')[0]['data']['text']);
        $this->assertSame('Lite team, stort håndverk', $page->localizedBlocks('nb')[0]['data']['text']);
        $this->assertSame('Your name', $form->fresh()->definitions('en')[0]['label']);
        $this->assertSame('Navnet ditt', $form->fresh()->definitions('nb')[0]['label']);

        $admin = $this->userWithRole(User::SUPER_ADMIN);

        $english = Livewire::actingAs($admin)
            ->test(DesignPage::class, ['record' => $page->getRouteKey()])
            ->assertSet('language', null);

        $this->assertSame('Small team, big craft', $english->get('blocks')[0]['data']['text']);

        $norwegian = Livewire::actingAs($admin)
            ->test(DesignPage::class, ['record' => $page->getRouteKey(), 'language' => 'nb']);

        $this->assertSame('Lite team, stort håndverk', $norwegian->get('blocks')[0]['data']['text']);
    }

    public function test_a_page_can_be_in_one_other_language_only(): void
    {
        $page = ContentLocale::using('nb', fn () => Page::factory()->create([
            'title' => 'Sommerjobb',
            'status' => PageStatus::Published,
            'published_at' => now()->subDay(),
            'locales' => ['nb'],
            'blocks' => BlockTreeBuilder::flatten([['type' => 'heading', 'data' => ['text' => 'Bli med i sommer', 'level' => 'h2']]]),
        ]));

        $this->assertSame('nb', $page->primaryLocale());
        $this->assertFalse($page->isTranslatable());
        $this->assertSame('/nb/sommerjobb', $page->path('en'));

        $this->get('/nb/sommerjobb')->assertOk()->assertSee('Bli med i sommer');
        $this->get('/sommerjobb')->assertNotFound();
    }

    public function test_a_form_is_translated_without_a_second_set_of_fields(): void
    {
        $form = Form::query()->create([
            'name' => 'Contact',
            'submit_label' => 'Send',
            'success_message' => 'Thanks!',
            'fields' => [
                ['type' => 'text', 'data' => ['label' => 'Your name', 'name' => 'name', 'required' => true]],
                ['type' => 'select', 'data' => ['label' => 'Topic', 'name' => 'topic', 'options' => ['Sales', 'Support'], 'required' => true]],
            ],
        ]);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditForm::class, ['record' => $form->getRouteKey()])
            ->call('setActiveLocale', 'nb')
            ->fillForm([
                'submit_label' => 'Send inn',
                'field_translations' => ['nb' => [
                    'name' => ['label' => 'Navnet ditt', 'help' => ''],
                    'topic' => ['label' => 'Emne', 'options' => ['Salg', 'Brukerstøtte']],
                ]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $form->refresh();

        $this->assertCount(2, $form->fields);
        $this->assertSame(['name' => ['label' => 'Navnet ditt'], 'topic' => ['label' => 'Emne', 'options' => ['Salg', 'Brukerstøtte']]], $form->field_translations['nb']);
        $this->assertSame('Send inn', $form->getTranslation('submit_label', 'nb'));
        $this->assertSame('Send', $form->getTranslation('submit_label', 'en'));
        $this->assertSame(['Salg', 'Brukerstøtte'], $form->definitions('nb')[1]['data']['options']);
        $this->assertSame('Your name', $form->definitions('en')[0]['label']);

        // A Norwegian visitor picks a Norwegian choice; the English one is refused.
        $post = fn (string $topic) => $this->from('/nb/contact')->post(route('forms.submit', $form), [
            'name' => 'Kari', 'topic' => $topic, '_locale' => 'nb', '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ]);

        $post('Sales')->assertSessionHasErrors('topic', null, 'form_'.$form->id);
        $post('Salg')->assertSessionHasNoErrors();
    }

    public function test_an_images_alt_text_and_caption_are_written_per_language(): void
    {
        Storage::fake('public');
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 400, 300), ['alt' => 'A fjord at dawn', 'caption' => 'Geiranger']);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditAsset::class, ['record' => $asset->getRouteKey()])
            ->call('setActiveLocale', 'nb')
            ->assertSchemaStateSet(['alt' => null], 'form')
            ->fillForm(['alt' => 'En fjord ved daggry'])
            ->call('save')
            ->assertHasNoFormErrors();

        $asset->refresh();

        $this->assertSame('A fjord at dawn', $asset->getTranslation('alt', 'en'));
        $this->assertSame('En fjord ved daggry', ContentLocale::using('nb', fn () => $asset->alt));
        // No Norwegian caption: the English one shows.
        $this->assertSame('Geiranger', ContentLocale::using('nb', fn () => $asset->caption));
    }

    public function test_switching_language_fills_a_required_field_without_a_translation_from_the_pages_language(): void
    {
        $page = $this->page();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->set('activeLocale', 'nb')
            ->assertSchemaStateSet(['title' => 'About us', 'excerpt' => null], 'form')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('About us', $page->fresh()->applyWorkingCopy()->getTranslation('title', 'nb', false));
    }

    public function test_a_page_is_duplicated_as_a_draft_with_its_translations(): void
    {
        $page = $this->page();
        $page->setTranslation('title', 'nb', 'Om oss')->forceFill(['block_translations' => ['nb' => [$page->blocks[0]['id'] => ['text' => 'Lite team']]]])->save();
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        Livewire::actingAs($admin)
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->mountAction('replicate')
            ->assertActionDataSet(['title' => 'About us (copy)'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $copy = Page::query()->whereKeyNot($page->getKey())->latest()->firstOrFail();

        $this->assertSame(PageStatus::Draft, $copy->status);
        $this->assertSame($admin->id, $copy->author_id);
        $this->assertSame(['en' => 'about-us-copy', 'nb' => 'om-oss-1'], $copy->getTranslations('slug'));
        $this->assertSame('Lite team', $copy->block_translations['nb'][$page->blocks[0]['id']]['text']);
        $this->assertCount(2, $copy->blocks);
    }

    public function test_a_page_is_duplicated_from_the_page_list(): void
    {
        $page = $this->page();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->callAction(TestAction::make('replicate')->table($page), ['title' => 'Team'])
            ->assertHasNoActionErrors();

        $this->assertSame('team', Page::query()->whereKeyNot($page->getKey())->latest()->firstOrFail()->slug);
    }

    public function test_saving_new_addresses_asks_which_old_ones_to_redirect_in_every_language(): void
    {
        $page = $this->page();
        $page->setTranslation('title', 'nb', 'Om oss')->save();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'the-team'])
            ->set('activeLocale', 'nb')
            ->fillForm(['slug' => 'teamet'])
            ->call('save')
            ->assertActionMounted('keepOldAddresses')
            ->assertActionDataSet(['redirect' => ['nb', 'en']])
            // Only the Norwegian one.
            ->setActionData(['redirect' => ['nb']])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(['en' => 'the-team', 'nb' => 'teamet'], $page->fresh()->getTranslations('slug'));
        $this->get('/nb/om-oss')->assertRedirect('/nb/teamet');
        $this->get('/about-us')->assertNotFound();
    }

    public function test_a_draft_saves_a_new_address_without_asking(): void
    {
        $page = Page::factory()->create(['title' => 'Draft page']);

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'renamed'])
            ->call('save')
            ->assertActionNotMounted()
            ->assertHasNoFormErrors();

        $this->assertSame('renamed', $page->fresh()->slug);
    }

    public function test_a_language_can_live_under_its_own_address_prefix(): void
    {
        $page = $this->page();
        $page->setTranslation('title', 'nb', 'Om oss')->save();
        Locale::query()->where('code', 'nb')->sole()->update(['prefix' => 'no']);

        $this->assertSame('/no/om-oss', $page->fresh()->path('nb'));
        $this->get('/no/om-oss')->assertOk()->assertSee('<html lang="nb"', false);
        $this->get('/nb/om-oss')->assertNotFound();

        $this->assertSame('A page already has this address.', Locale::prefixProblem('about-us', 'nb'));
        $this->assertSame('This address is reserved by the application.', Locale::prefixProblem('admin', 'nb'));
        $this->assertNull(Locale::prefixProblem('norsk', 'nb'));
        $this->assertNull(Locale::prefixProblem('nb', 'nb'), 'A language may live under its own code.');
        $this->assertNull(Locale::prefixProblem('about-us', 'nb', againstExisting: false), 'Starting over: the pages are going.');
    }

    public function test_the_cms_change_summaries_read_in_the_admins_language(): void
    {
        $page = $this->page();
        $page->fresh()->update(['title' => 'About the team', 'meta_title' => 'Team']);

        app()->setLocale('nb');

        $summaries = $page->revisions()->oldest('id')->get()->map->summaryText()->all();

        $this->assertContains('Opprettet siden', $summaries);
        $this->assertContains('Endret tittel, SEO', $summaries);
        $this->assertSame('Redesigned the pricing', $page->revisions()->create(['source' => 'mcp', 'summary' => 'Redesigned the pricing', 'snapshot' => []])->summaryText(), 'An assistant’s own words stay as written.');
    }

    public function test_fields_that_are_the_same_in_every_language_are_not_offered_for_translation(): void
    {
        $fields = BlockTranslations::fieldsOf('code-sample');

        $this->assertContains('caption', $fields);
        $this->assertNotContains('filename', $fields, 'A file name is the same in every language.');
        $this->assertNotContains('code', $fields, 'Code is never translated.');
    }
}
