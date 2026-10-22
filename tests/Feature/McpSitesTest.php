<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\AiChannel;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\AddMenuItem;
use Aurora\Mcp\Tools\CreatePage;
use Aurora\Mcp\Tools\ListPages;
use Aurora\Mcp\Tools\ListSites;
use Aurora\Mcp\Tools\RemoveMenuItem;
use Aurora\Mcp\Tools\UpdateMenuItem;
use Aurora\Mcp\Tools\WhoAmI;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class McpSitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_one_site_the_tools_need_no_site(): void
    {
        Page::factory()->create(['title' => 'About us']);

        CmsServer::actingAs($this->userWithRole('Editor'))->tool(ListPages::class)->assertOk()->assertSee('About us');
    }

    public function test_with_several_sites_the_tools_ask_which_one_and_work_on_it(): void
    {
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        Page::factory()->create(['title' => 'Main about']);
        CurrentSite::using($shop, fn () => Page::factory()->create(['title' => 'Shop about']));
        $menu = CurrentSite::using($shop, fn () => Menu::query()->create(['name' => 'Shop menu', 'is_visible' => true]));
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        CmsServer::actingAs($admin)->tool(ListPages::class)->assertHasErrors(['several sites', 'shop (Shop)']);

        CmsServer::actingAs($admin)->tool(ListPages::class, ['site' => 'shop'])->assertOk()->assertSee('Shop about')->assertDontSee('Main about');

        CmsServer::actingAs($admin)->tool(CreatePage::class, ['site' => 'shop', 'title' => 'Opening hours'])->assertOk();
        CmsServer::actingAs($admin)->tool(AddMenuItem::class, ['site' => 'shop', 'menu' => $menu->id, 'title' => 'Hours', 'url' => '/opening-hours'])->assertOk();

        $this->assertSame($shop->id, Page::acrossSites()->where('title->en', 'Opening hours')->sole()->site_id);
        $this->assertSame(1, CurrentSite::using($shop, fn () => $menu->menuItems()->count()));

        // Shared tools need no site, and say which sites there are.
        CmsServer::actingAs($admin)->tool(WhoAmI::class)->assertOk()->assertSee('several sites');
        CmsServer::actingAs($admin)->tool(ListSites::class)->assertOk()->assertSee('shop');
        $this->assertArrayHasKey('site', app(ListPages::class)->toArray()['inputSchema']['properties']);
    }

    public function test_a_site_one_cannot_work_on_is_refused(): void
    {
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        CurrentSite::using($shop, fn () => Page::factory()->create(['title' => 'Shop about']));
        $editor = $this->userWithRole('Editor');

        // Given no site, the editor works on the default one only.
        CmsServer::actingAs($editor)->tool(ListPages::class, ['site' => 'shop'])->assertHasErrors(['No site "shop"']);
        CmsServer::actingAs($editor)->tool(ListPages::class)->assertOk()->assertDontSee('Shop about');
    }

    public function test_the_admins_assistant_keeps_the_site_being_worked_on(): void
    {
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        CurrentSite::using($shop, fn () => Page::factory()->create(['title' => 'Shop about']));
        CurrentSite::set($shop);

        AiChannel::asAssistant(fn () => CmsServer::actingAs($this->userWithRole(User::SUPER_ADMIN))->tool(ListPages::class)->assertOk()->assertSee('Shop about'));
    }

    public function test_menu_items_on_another_site_cannot_be_reached_by_id(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        $item = CurrentSite::using($shop, function () {
            $menu = Menu::query()->create(['name' => 'Shop menu', 'is_visible' => true]);

            return $menu->menuItems()->create(['title' => 'Hours', 'url' => '/hours', 'order' => 1]);
        });

        // Working on the main site: the shop's item isn't there to change or remove.
        CmsServer::actingAs($admin)->tool(RemoveMenuItem::class, ['site' => 'main', 'item_id' => $item->id])->assertHasErrors();
        CmsServer::actingAs($admin)->tool(UpdateMenuItem::class, ['site' => 'main', 'item_id' => $item->id, 'title' => 'Hacked'])->assertHasErrors();

        $this->assertSame('Hours', CurrentSite::using($shop, fn () => MenuItem::query()->find($item->id)?->title));
    }
}
