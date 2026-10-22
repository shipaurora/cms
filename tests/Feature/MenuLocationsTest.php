<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\MenuBuilderPlugin;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Menus\Pages\ListMenus;
use Aurora\Models\Menu;
use Aurora\Models\MenuLocation;
use Aurora\Models\NavigationLocation;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class MenuLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_site_starts_with_the_locations_its_templates_read(): void
    {
        $this->assertSame(
            ['header' => 'Main navigation', 'footer' => 'Footer'],
            MenuBuilderPlugin::get()->getLocations(),
        );
    }

    public function test_a_new_location_is_added_and_menus_are_shown_in_it(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'templates'])
            ->test(SiteSettings::class)
            ->assertSee('Main navigation')
            ->callAction(TestAction::make('addMenuLocation')->schemaComponent('section.menu-locations.add-menu-location', schema: 'form'), data: [
                'name' => 'Sidebar', 'handle' => 'sidebar', 'description' => 'Beside the blog posts.',
            ])
            ->assertHasNoActionErrors()
            ->assertSee('Sidebar');

        $this->assertArrayHasKey('sidebar', MenuBuilderPlugin::get()->getLocations());

        $menu = Menu::query()->create(['name' => 'Blog links', 'is_visible' => true]);
        MenuLocation::query()->create(['menu_id' => $menu->id, 'location' => 'sidebar']);
        $this->assertTrue(Menu::location('sidebar')->is($menu));
    }

    public function test_removing_a_location_takes_its_menus_out_of_it(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        $menu = Menu::query()->create(['name' => 'Legal', 'is_visible' => true]);
        $legal = NavigationLocation::query()->create(['handle' => 'legal', 'name' => 'Footer: legal links']);
        MenuLocation::query()->create(['menu_id' => $menu->id, 'location' => 'legal']);

        Livewire::test(ListMenus::class)
            ->mountAction('menuLocations')
            ->callAction(TestAction::make('deleteMenuLocation')->schemaComponent('menu-location-'.$legal->id, schema: 'mountedActionSchema0'));

        $this->assertModelMissing($legal);
        $this->assertModelExists($menu);
        $this->assertNull(Menu::location('legal'));
    }

    public function test_only_those_who_manage_settings_change_locations(): void
    {
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListMenus::class)
            ->assertActionHidden('menuLocations');
    }
}
