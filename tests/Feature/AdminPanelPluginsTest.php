<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\AssetTag;
use Aurora\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Filament\Resources\AssetTags\AssetTagResource;

class AdminPanelPluginsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plugin_the_website_registers_is_added_to_the_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasPlugin('workbench-tags'));
        $this->assertContains(AssetTagResource::class, $panel->getResources());
    }

    public function test_the_resources_a_plugin_brings_show_every_sites_records(): void
    {
        AssetTag::query()->create(['name' => 'Fjords']);

        $this->assertFalse(AssetTagResource::isScopedToTenant());
        $this->actingAs($this->userWithRole('Super Admin'))
            ->get(AssetTagResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Fjords');
    }
}
