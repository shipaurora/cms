<?php

namespace Aurora\Tests\Feature;

use Aurora\Aurora;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\LanguageLines\LanguageLineResource;
use Aurora\Filament\Resources\LanguageLines\Pages\ManageLanguageLines;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Filament\Resources\Roles\Pages\EditRole;
use Aurora\Filament\Resources\Roles\RelationManagers\UsersRelationManager;
use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Livewire\LanguagesTable;
use Aurora\Models\LanguageLine;
use Aurora\Models\Locale;
use Aurora\Models\Role;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\AiTranslator;
use Aurora\Support\AssetLibrary;
use Aurora\Support\Copilot;
use Aurora\Support\TranslationSync;
use Aurora\Tests\TestCase;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class TranslationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Locale::factory()->default()->create(['code' => 'en', 'name' => 'English']);
        Locale::factory()->create(['code' => 'nb', 'name' => 'Norsk bokmål']);
    }

    protected function line(string $group, string $key): LanguageLine
    {
        return LanguageLine::query()->where('group', $group)->where('key', $key)->firstOrFail();
    }

    public function test_import_copies_every_label_from_the_lang_files_with_its_texts(): void
    {
        $result = app(TranslationSync::class)->import();

        $this->assertGreaterThan(1500, $result['found']);
        $this->assertSame($result['found'], $result['added']);
        $this->assertSame('Takk!', $this->line('*', 'Thank you!')->text['nb']);
        $this->assertSame(':Attribute må fylles ut.', $this->line('validation', 'required')->text['nb']);
        $this->assertSame('Logg ut', $this->line('filament-panels::layout', 'actions.logout.label')->text['nb']);
        $this->assertSame('Overskrift', $this->line('page-builder::blocks', 'heading.label')->text['nb']);

        $this->assertSame(0, app(TranslationSync::class)->import()['added']);
    }

    public function test_labels_come_from_the_lang_files_before_there_is_a_database(): void
    {
        // A project just created: its SQLite file doesn't exist yet (composer create-project
        // boots the app for package:discover before anything has been migrated).
        $connection = config('database.default');
        config(['database.connections.not-created-yet' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/aurora-'.uniqid().'.sqlite', 'prefix' => '']]);
        config(['database.default' => 'not-created-yet']);

        try {
            $labels = app('translation.loader')->load('nb', '*', '*');
        } finally {
            config(['database.default' => $connection]);
        }

        $this->assertSame('Slik lager du en blokk', $labels['How to make a block'] ?? null);
    }

    public function test_reimporting_with_the_languages_in_another_order_changes_nothing(): void
    {
        app(TranslationSync::class)->import();

        // Norwegian becomes the default, so it now comes first.
        Locale::query()->where('code', 'en')->update(['is_default' => false]);
        Locale::query()->where('code', 'nb')->firstOrFail()->update(['is_default' => true]);
        $this->assertSame(['nb', 'en'], array_keys(Locale::options()));

        $this->assertSame(0, app(TranslationSync::class)->import()['updated']);
    }

    public function test_reimporting_keeps_edits_and_reset_brings_back_the_lang_file(): void
    {
        app(TranslationSync::class)->import();
        $this->line('*', 'Thank you!')->putTranslation('nb', 'Tusen takk!')->save();

        app(TranslationSync::class)->import();

        $line = $this->line('*', 'Thank you!');
        $this->assertSame('Tusen takk!', $line->text['nb']);
        $this->assertSame(['nb'], $line->editedLocales());

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ManageLanguageLines::class)
            ->callTableAction('reset', $line);

        $this->assertSame('Takk!', $line->fresh()->text['nb']);
        $this->assertSame([], $line->fresh()->editedLocales());
    }

    public function test_the_code_scan_finds_translated_strings_and_filament_labels(): void
    {
        $keys = app(TranslationSync::class)->keysInCode();

        $this->assertContains('Thank you!', $keys);
        $this->assertContains('Preferred language', $keys);
        $this->assertContains('Job title', $keys, 'Labels Filament makes from field names.');
    }

    public function test_edits_override_the_lang_files_per_locale_for_every_kind_of_label(): void
    {
        app(TranslationSync::class)->import();

        $this->line('*', 'Thank you!')->putTranslation('nb', 'Takk!')->save();
        $this->line('validation', 'required')->putTranslation('nb', ':Attribute må fylles ut.')->save();
        $this->line('filament-panels::layout', 'actions.logout.label')->putTranslation('nb', 'Logg av')->save();
        app('translator')->setLoaded([]);

        $this->assertSame('Takk!', __('Thank you!', [], 'nb'));
        $this->assertSame('Thank you!', __('Thank you!', [], 'en'));
        $this->assertSame('Navn må fylles ut.', __('validation.required', ['attribute' => 'navn'], 'nb'));
        $this->assertSame('The name field is required.', __('validation.required', ['attribute' => 'name'], 'en'));
        $this->assertSame('Logg av', __('filament-panels::layout.actions.logout.label', [], 'nb'));
        $this->assertSame('Logga ut', __('filament-panels::layout.actions.logout.label', [], 'sv'), 'Other languages keep the package text.');

        $this->line('filament-panels::layout', 'actions.logout.label')->putTranslation('nb', null)->save();
        app('translator')->setLoaded([]);

        $this->assertSame('Logg ut', __('filament-panels::layout.actions.logout.label', [], 'nb'), 'Clearing an edit falls back to the lang file.');
    }

    public function test_the_website_speaks_the_default_language(): void
    {
        Locale::query()->where('code', 'nb')->first()->update(['is_default' => true]);

        $this->get('/robots.txt')->assertOk();
        $this->assertSame('nb', app()->getLocale());
        $this->assertFalse(Locale::query()->where('code', 'en')->value('is_default'));
    }

    public function test_the_admin_uses_the_sites_language_when_a_person_has_not_picked_one(): void
    {
        Site::default()->update(['default_locale' => 'nb']);
        $editor = $this->userWithRole('Editor');
        $editor->update(['locale' => null]);

        $this->actingAs($editor)->get('/admin/main')->assertOk()->assertSee('lang="nb"', false);
        $this->assertSame('en', Locale::defaultCode());
    }

    public function test_the_admin_speaks_each_persons_preferred_language(): void
    {
        $editor = $this->userWithRole('Editor');
        $editor->update(['locale' => 'nb']);

        $this->actingAs($editor)->get('/admin/main')->assertOk()->assertSee('lang="nb"', false);

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertSee('Foretrukket språk')
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertSame('en', $editor->fresh()->locale);

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->fillForm(['locale' => 'xx'])
            ->call('save')
            ->assertHasFormErrors(['locale']);
    }

    public function test_translations_are_edited_in_place_and_synced_from_the_admin(): void
    {
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(ManageLanguageLines::class)
            ->callAction('sync')
            ->assertNotified();

        $this->assertGreaterThan(1500, LanguageLine::query()->count());

        $line = $this->line('*', 'Thank you!');

        Livewire::actingAs($editor)
            ->test(ManageLanguageLines::class)
            ->assertSet('activeTab', 'nb')
            ->call('updateTableColumnState', 'translation', $line->getKey(), 'Tusen takk!');

        $this->assertSame('Tusen takk!', $line->fresh()->text['nb']);

        Livewire::actingAs($editor)
            ->test(ManageLanguageLines::class)
            ->callTableAction('allLanguages', $line, ['en' => 'Thanks!', 'nb' => 'Takk skal du ha!'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(['en' => 'Thanks!', 'nb' => 'Takk skal du ha!'], array_intersect_key($line->fresh()->text, ['en' => 1, 'nb' => 1]));
    }

    public function test_only_permitted_people_manage_languages_and_translations(): void
    {
        $this->actingAs($this->userWithRole('Author'));
        $this->assertFalse(LanguageLineResource::canViewAny());
        $this->assertFalse(SiteSettings::canManageLanguages());

        $this->actingAs($this->userWithRole('Editor'));
        $this->assertTrue(LanguageLineResource::canViewAny());
        $this->assertFalse(SiteSettings::canManageLanguages());

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        $this->assertTrue(SiteSettings::canManageLanguages());
    }

    public function test_selection_counts_name_what_the_table_lists(): void
    {
        $editor = $this->userWithRole('Editor');
        // Something to select: with nothing selectable, there's no selection to count.
        Storage::fake('public');
        AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 20, 20));

        Livewire::actingAs($editor)->test(ListAssets::class)->assertSeeHtml('1 file selected|:count files selected');
        Livewire::actingAs($editor)->test(ListPages::class)->assertSeeHtml('1 page selected|:count pages selected');
    }

    public function test_naming_the_selection_keeps_the_rest_of_the_table_translated(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        app()->setLocale('nb');

        Livewire::actingAs($admin)
            ->test(UsersRelationManager::class, ['ownerRecord' => Role::findByName('Editor'), 'pageClass' => EditRole::class])
            ->assertSeeHtml('placeholder="Søk"')
            ->assertDontSeeHtml('filament-tables::table.');
    }

    public function test_translations_live_under_settings_for_people_who_manage_languages(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        $this->assertFalse(LanguageLineResource::shouldRegisterNavigation());

        $breadcrumbs = Livewire::test(ManageLanguageLines::class)->instance()->getBreadcrumbs();
        $this->assertSame([SiteSettings::getUrl() => 'Settings', SiteSettings::languagesUrl() => 'Languages'], $breadcrumbs);

        $this->actingAs($this->userWithRole('Editor'));
        $this->assertTrue(LanguageLineResource::shouldRegisterNavigation(), 'Editors can translate but not open Languages.');
        $this->assertSame([], Livewire::test(ManageLanguageLines::class)->instance()->getBreadcrumbs());
    }

    public function test_translate_from_a_language_shows_only_that_language(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $page = Livewire::withQueryParams(['locale' => 'nb'])->test(ManageLanguageLines::class);

        $this->assertSame([], $page->instance()->getTabs());
        $this->assertSame('Translate into Norsk bokmål', $page->instance()->getTitle());
        $this->assertSame('nb', LanguageLineResource::translatingInto($page->instance()));
    }

    public function test_languages_are_managed_in_a_settings_tab(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        $this->actingAs($admin)->get(SiteSettings::languagesUrl())->assertOk()->assertSeeLivewire(LanguagesTable::class);

        Livewire::actingAs($admin)
            ->test(LanguagesTable::class)
            ->assertCanSeeTableRecords(Locale::all())
            ->callTableAction('create', data: ['code' => 'sv', 'name' => 'Svenska', 'is_active' => true])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Locale::isActiveCode('sv'));
    }

    public function test_translate_all_fills_in_a_languages_missing_labels_with_ai(): void
    {
        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']]);
        Copilot::storeApiKey('sk-ant-test-1234');
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $missing = LanguageLine::query()->create(['group' => '*', 'key' => 'Opening hours', 'text' => ['en' => 'Opening hours']]);
        $done = LanguageLine::query()->create(['group' => '*', 'key' => 'Closed', 'text' => ['en' => 'Closed', 'nb' => 'Stengt']]);

        AiTranslator::fake(fn (string $prompt): string => json_encode(
            collect(json_decode(Str::after($prompt, ':'), true))->map(fn (string $text): string => 'NB '.$text)->all(),
        ));

        Livewire::withQueryParams(['locale' => 'nb'])
            ->test(ManageLanguageLines::class)
            ->assertActionVisible('translateAll')
            ->callAction('translateAll')
            ->assertNotified('Translated 1 label')
            ->assertActionHidden('translateAll');

        $this->assertSame('NB Opening hours', $missing->fresh()->text['nb']);
        $this->assertSame('Stengt', $done->fresh()->text['nb']);

        Livewire::test(ManageLanguageLines::class)->assertActionHidden('translateAll');
    }

    public function test_there_is_always_a_default_language(): void
    {
        $english = Locale::query()->where('code', 'en')->sole();

        try {
            $english->update(['is_default' => false]);
            $this->fail('The default was unticked.');
        } catch (ValidationException) {
        }

        try {
            $english->fresh()->delete();
            $this->fail('The default was deleted.');
        } catch (ValidationException) {
        }

        // Making another language the default is how it changes.
        Locale::query()->where('code', 'nb')->sole()->update(['is_default' => true]);

        $this->assertSame('nb', Locale::defaultCode());
        $this->assertFalse($english->fresh()->is_default);
    }

    public function test_filament_labels_go_through_the_translator(): void
    {
        app()->setLocale('nb');

        $this->assertSame('Sider', TextInput::make('pages')->label('Pages')->getLabel());
        $this->assertSame('Sider', TextColumn::make('pages')->label('Pages')->getLabel());
        $this->assertSame('Sider', SelectFilter::make('pages')->label('Pages')->getLabel());
    }

    public function test_a_language_is_switched_on_and_off_from_the_table(): void
    {
        $this->withEnglishAndNorwegian();
        $norwegian = Locale::query()->where('code', 'nb')->sole();
        $english = Locale::query()->where('is_default', true)->sole();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(LanguagesTable::class)
            ->call('updateTableColumnState', 'is_active', $norwegian->getKey(), false)
            // The default language's switch is disabled: it stays on.
            ->call('updateTableColumnState', 'is_active', $english->getKey(), false);

        $this->assertFalse($norwegian->fresh()->is_active);
        $this->assertTrue($english->fresh()->is_active);
        $this->assertArrayNotHasKey('nb', Locale::options());
    }

    public function test_every_label_in_the_code_is_in_both_language_files(): void
    {
        // The CMS's labels in its own lang files; the website's own (its blocks, its layouts) in
        // the app's, in Norwegian only: an English label is its own text.
        $website = is_file(lang_path('nb.json')) ? json_decode(file_get_contents(lang_path('nb.json')), true) : [];
        $english = json_decode(file_get_contents(Aurora::path('lang/en.json')), true) + $website;
        $norwegian = json_decode(file_get_contents(Aurora::path('lang/nb.json')), true) + $website;

        // Sentence labels; package keys ("vendor::file.key") and group keys ("file.key") live elsewhere.
        $keys = array_filter(app(TranslationSync::class)->keysInCode(), fn (string $key): bool => ! str_contains($key, '::') && ! preg_match('/^[a-z0-9_-]+\.[a-z0-9_.-]+$/i', $key));

        $this->assertSame([], array_values(array_diff($keys, array_keys($english))), 'Missing from lang/en.json');
        $this->assertSame([], array_values(array_diff($keys, array_keys($norwegian))), 'Missing from lang/nb.json');
    }

    public function test_a_long_label_with_a_dot_is_not_looked_for_as_a_lang_file(): void
    {
        $label = str_repeat('Find what helps pages in search results. ', 8);

        // Laravel looks for "Find what … results" as a lang file's group when no JSON file has
        // the label: nothing to find, and no cache entry named after the whole sentence.
        $this->assertSame($label, __($label, [], 'nb'));
        $this->assertSame([], app('translation.loader')->load('nb', Str::before($label, '.')));
        $this->assertFalse(Cache::has(LanguageLine::getCacheKey(Str::before($label, '.'), 'nb')));
    }
}
