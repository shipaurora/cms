<?php

namespace Aurora\Tests\Feature;

use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\AddBlock;
use Aurora\Mcp\Tools\CreatePage;
use Aurora\Mcp\Tools\ListBlockTypes;
use Aurora\Mcp\Tools\SetPageBlocks;
use Aurora\Models\Page;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Support\Permissions;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class McpCustomHtmlTest extends TestCase
{
    use RefreshDatabase;

    protected const CODE = ['type' => 'code', 'data' => ['html' => '<marquee>Hello</marquee>']];

    public function test_no_default_role_may_write_custom_html(): void
    {
        foreach (Permissions::roles() as $role => $definition) {
            $this->assertNotContains('use-code-blocks', $definition['permissions'], $role);
        }

        $editor = $this->userWithRole('Editor');

        CmsServer::actingAs($editor)->tool(ListBlockTypes::class)->assertOk()->assertDontSee('"type":"code"', false);
        CmsServer::actingAs($editor)->tool(CreatePage::class, ['title' => 'Custom', 'blocks' => [self::CODE], 'custom_html' => true])
            ->assertHasErrors(['you are not allowed to use "code" blocks']);

        $this->assertFalse(Page::query()->where('title->en', 'Custom')->exists());
    }

    public function test_custom_html_is_added_only_once_the_user_agreed(): void
    {
        $user = $this->trustedUser();

        CmsServer::actingAs($user)->tool(ListBlockTypes::class)->assertOk()->assertSee('"type":"code"', false);

        // Not without the user's say-so, nested or not, in any tool that adds blocks.
        CmsServer::actingAs($user)->tool(CreatePage::class, ['title' => 'Landing', 'blocks' => [['type' => 'band', 'children' => [self::CODE]]]])
            ->assertHasErrors(['ask the user first']);
        $this->assertFalse(Page::query()->where('title->en', 'Landing')->exists());

        $page = Page::factory()->create();

        CmsServer::actingAs($user)->tool(AddBlock::class, ['page' => $page->id, 'block' => self::CODE])->assertHasErrors(['ask the user first']);
        CmsServer::actingAs($user)->tool(SetPageBlocks::class, ['page' => $page->id, 'blocks' => [self::CODE]])->assertHasErrors(['ask the user first']);
        $this->assertSame(0, collect($page->refresh()->blocks)->where('type', 'code')->count());

        // With it.
        CmsServer::actingAs($user)->tool(AddBlock::class, ['page' => $page->id, 'block' => self::CODE, 'custom_html' => true])->assertOk();
        $this->assertSame(1, collect($page->refresh()->blocks)->where('type', 'code')->count());

        // Rebuilding a page that already has it needs no new say-so; adding a second one does.
        CmsServer::actingAs($user)->tool(SetPageBlocks::class, ['page' => $page->id, 'blocks' => [['type' => 'heading', 'data' => ['text' => 'Hi']], self::CODE]])->assertOk();
        CmsServer::actingAs($user)->tool(SetPageBlocks::class, ['page' => $page->id, 'blocks' => [self::CODE, self::CODE]])->assertHasErrors(['ask the user first']);
    }

    protected function trustedUser(): User
    {
        $user = $this->userWithRole('Editor');
        Role::findOrCreate('Developer', 'web')->givePermissionTo('use-code-blocks');

        return $user->assignRole('Developer');
    }
}
