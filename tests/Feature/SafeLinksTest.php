<?php

namespace Aurora\Tests\Feature;

use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\AddMenuItem;
use Aurora\Mcp\Tools\UpdateMenuItem;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Support\SafeUrl;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SafeLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_safe_links_are_accepted(): void
    {
        foreach (['/about', '#team', 'https://example.com/a?b=c', 'mailto:hi@example.com', 'tel:+47 55 00 00 00'] as $url) {
            $this->assertTrue(SafeUrl::isSafe($url), $url);
        }

        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,x', '//evil.com', '/\\evil.com', 'vbscript:x', ''] as $url) {
            $this->assertFalse(SafeUrl::isSafe($url), $url);
        }
    }

    public function test_an_unsafe_menu_link_or_announcement_never_reaches_the_page(): void
    {
        Page::factory()->homepage()->create();
        $menu = Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
        $menu->locations()->create(['location' => 'header']);
        MenuItem::query()->create(['menu_id' => $menu->id, 'title' => 'Click me', 'url' => 'javascript:alert(document.cookie)', 'order' => 1]);
        Setting::put(['announcement' => ['enabled' => true, 'text' => 'Hi', 'link_label' => 'Go', 'url' => 'javascript:alert(1)']]);

        $this->get('/')->assertOk()->assertSee('Click me')->assertDontSee('javascript:', false);
    }

    public function test_ai_assistants_cannot_add_unsafe_links_or_loop_menu_items(): void
    {
        $editor = $this->userWithRole('Editor');
        $menu = Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
        $parent = MenuItem::query()->create(['menu_id' => $menu->id, 'title' => 'Services', 'order' => 1]);
        $child = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'title' => 'Design', 'order' => 1]);

        CmsServer::actingAs($editor)->tool(AddMenuItem::class, ['menu' => $menu->id, 'title' => 'Evil', 'url' => 'javascript:alert(1)'])
            ->assertHasErrors(['web address']);

        CmsServer::actingAs($editor)->tool(UpdateMenuItem::class, ['item_id' => $parent->id, 'parent_id' => $child->id])
            ->assertHasErrors(['not under itself']);

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_the_site_name_cannot_break_out_of_the_structured_data(): void
    {
        Page::factory()->homepage()->create();
        Setting::put(['site_name' => '</script><script>alert(1)</script>']);

        $this->get('/')->assertOk()->assertDontSee('</script><script>alert(1)', false);
    }
}
