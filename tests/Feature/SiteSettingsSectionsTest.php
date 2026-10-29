<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Forms\Components\SecretInput;
use Aurora\Filament\GlobalSearchProvider;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\ImageFormat;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\PaletteCommands;
use Aurora\Support\SettingSecrets;
use Aurora\Support\SettingsTabs;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class SiteSettingsSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_section_is_an_item_in_the_side_menu_and_opens_on_its_own(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $settings = Livewire::test(SiteSettings::class);
        $keys = collect($settings->instance()->getSubNavigation())->map(fn ($item) => str($item->getUrl())->after('tab=')->toString())->all();

        $this->assertSame(['website', 'templates', 'media', 'languages-sites', 'search-seo', 'performance', 'integrations', 'security', 'appearance', 'ai', 'system'], $keys);
        $settings->assertFormFieldExists('section.branding.site_name')->assertFormFieldDoesNotExist('copilot.provider');

        // Unknown: the first.
        $this->assertSame('website', Livewire::withQueryParams(['tab' => 'nope'])->test(SiteSettings::class)->instance()->sectionKey());
    }

    public function test_saving_a_section_keeps_the_others_settings(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Setting::put(['tagline' => 'Kept', 'security' => ['require_two_factor' => true]]);

        Livewire::withQueryParams(['tab' => 'website'])
            ->test(SiteSettings::class)
            ->fillForm(['site_name' => 'Nordlys'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nordlys', Setting::get('site_name'));
        $this->assertSame('Kept', Setting::get('tagline'));
        $this->assertTrue(Setting::get('security')['require_two_factor']);
    }

    public function test_layouts_and_image_formats_are_changed_right_in_settings(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'templates'])
            ->test(SiteSettings::class)
            ->assertActionHidden('save')
            ->assertSee('Header, menu and footer.')
            ->callAction(TestAction::make('addLayout')->schemaComponent('section.layouts.add-layout', schema: 'form'), data: [
                'name' => 'Campaign', 'handle' => 'campaign', 'view' => 'aurora::layouts.standard',
            ])
            ->assertHasNoActionErrors()
            ->assertSee('Campaign');
        $this->assertTrue(Layout::query()->where('handle', 'campaign')->exists());

        Livewire::withQueryParams(['tab' => 'media'])
            ->test(SiteSettings::class)
            ->assertSee(ImageFormat::cached()->first()->name);
    }

    public function test_the_search_index_is_tried_in_settings_without_it_being_a_change_to_save(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Page::factory()->published()->create(['title' => 'Opening hours', 'slug' => 'hours']);

        Livewire::withQueryParams(['tab' => 'search-seo'])
            ->test(SiteSettings::class)
            ->assertSee('Reindex all')
            ->set('searchQuery', 'opening')
            ->assertSee('/hours')
            ->assertSet('searchQuery', 'opening')
            ->tap(fn ($settings) => $this->assertFalse($settings->instance()->hasUnsavedChanges()));
    }

    public function test_the_header_and_footer_are_one_section_with_a_tab_each(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'website'])
            ->test(SiteSettings::class)
            ->assertSee(['Header', 'Footer', 'Announcement bar'])
            ->fillForm(['header_cta_label' => 'Start a project', 'footer_note' => 'Made in Bergen'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Start a project', Setting::get('header_cta_label'));
        $this->assertSame('Made in Bergen', Setting::get('footer_note'));
    }

    public function test_settings_sections_are_found_in_global_search_and_the_command_palette(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $results = app(GlobalSearchProvider::class)->getResults('layouts')->getCategories()->get('Settings', []);
        $this->assertSame([SiteSettings::getUrl(['tab' => 'templates', 'section' => 'layouts'])], collect($results)->pluck('url')->all());

        $commands = collect(PaletteCommands::all())->filter(fn ($command) => $command->toArray()['group'] === 'Settings');
        $this->assertContains(SiteSettings::getUrl(['tab' => 'media', 'section' => 'image-formats']), $commands->map(fn ($command) => $command->toArray()['url'])->all());

        // Only for those who may open Settings.
        $this->actingAs($this->userWithRole('Editor'));
        $this->assertSame([], SiteSettings::sectionLinks());
        $this->assertArrayNotHasKey('Settings', app(GlobalSearchProvider::class)->getResults('layouts')->getCategories()->all());
    }

    public function test_a_websites_own_page_gathers_its_tabs_in_one_side_menu_item(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        SettingsTabs::page('shop', fn (): string => 'Shop', 'phosphor-storefront');
        SettingsTabs::register('shipping', fn (): Tab => Tab::make('Shipping')->schema([TextInput::make('shipping_note')]), page: 'shop');
        SettingsTabs::register('payments', fn (): Tab => Tab::make('Payments')->schema([TextInput::make('payments_note')]), page: 'shop');
        SettingsTabs::register('mailchimp', fn (): Tab => Tab::make('Mailchimp')->schema([TextInput::make('mailchimp_list')]), page: 'integrations');

        try {
            $keys = collect(Livewire::test(SiteSettings::class)->instance()->getSubNavigation())->map(fn ($item) => str($item->getUrl())->after('tab=')->toString())->all();

            $this->assertSame(['website', 'shop', 'templates', 'media', 'languages-sites', 'search-seo', 'performance', 'integrations', 'security', 'appearance', 'ai', 'system'], $keys);
            Livewire::withQueryParams(['tab' => 'shop'])->test(SiteSettings::class)->assertSee(['Shipping', 'Payments']);
            Livewire::withQueryParams(['tab' => 'integrations'])->test(SiteSettings::class)->assertSee(['REST API', 'Mailchimp']);
        } finally {
            SettingsTabs::forget('shipping');
            SettingsTabs::forget('payments');
            SettingsTabs::forget('mailchimp');
            SettingsTabs::forgetPage('shop');
        }
    }

    public function test_a_secret_is_stored_encrypted_and_never_sent_to_the_browser(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        SettingsTabs::register('mailchimp', fn (): Tab => Tab::make('Mailchimp')->schema([
            TextInput::make('mailchimp_list'),
            SecretInput::make('mailchimp_api_key')->label('API key'),
        ]), page: 'integrations', publicKeys: ['mailchimp_list', 'mailchimp_api_key'], secretKeys: ['mailchimp_api_key']);

        try {
            Livewire::withQueryParams(['tab' => 'integrations', 'section' => 'mailchimp'])
                ->test(SiteSettings::class)
                ->fillForm(['mailchimp_list' => 'News', 'mailchimp_api_key' => 'abc123-us21'])
                ->call('save')
                ->assertHasNoFormErrors();

            $stored = Setting::get('mailchimp_api_key');
            $this->assertSame('abc123-us21', SettingSecrets::get('mailchimp_api_key'));
            $this->assertNotSame('abc123-us21', $stored);
            $this->assertSame('News', Setting::get('mailchimp_list'));

            // Opened again: neither the secret nor its encrypted form, only its last characters.
            Livewire::withQueryParams(['tab' => 'integrations', 'section' => 'mailchimp'])
                ->test(SiteSettings::class)
                ->assertDontSee('abc123-us21')
                ->assertDontSee($stored)
                ->assertSee('••••us21')
                // Saved with the field left empty: the secret stays.
                ->call('save');

            $this->assertSame('abc123-us21', SettingSecrets::get('mailchimp_api_key'));
            // Named public by the tab, still never public.
            $this->assertContains('mailchimp_list', SettingsTabs::publicKeys());
            $this->assertNotContains('mailchimp_api_key', SettingsTabs::publicKeys());
        } finally {
            SettingsTabs::forget('mailchimp');
        }
    }
}
