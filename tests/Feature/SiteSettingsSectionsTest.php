<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\GlobalSearchProvider;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\ImageFormat;
use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\PaletteCommands;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
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
}
