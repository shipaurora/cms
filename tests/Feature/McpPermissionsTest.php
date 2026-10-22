<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Enums\RevisionSource;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\AddBlock;
use Aurora\Mcp\Tools\AddMenuItem;
use Aurora\Mcp\Tools\CreatePage;
use Aurora\Mcp\Tools\GetMenus;
use Aurora\Mcp\Tools\GetPage;
use Aurora\Mcp\Tools\ListBlockTypes;
use Aurora\Mcp\Tools\PublishPage;
use Aurora\Mcp\Tools\SetPageBlocks;
use Aurora\Mcp\Tools\UpdateBlock;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Mcp\Tools\WhoAmI;
use Aurora\Models\McpActivity;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request as McpRequest;

class McpPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_author_can_create_a_draft_page_with_content(): void
    {
        $author = $this->userWithRole('Author');

        CmsServer::actingAs($author)->tool(CreatePage::class, [
            'title' => 'Accessibility audits',
            'blocks' => [
                ['type' => 'band', 'data' => ['background' => 'muted'], 'children' => [
                    ['type' => 'heading', 'data' => ['text' => 'Inclusive by *default*', 'level' => 'h1']],
                    ['type' => 'grid', 'data' => ['columns' => '2'], 'children' => [
                        ['type' => 'feature', 'data' => ['icon' => 'shield-check', 'title' => 'WCAG 2.2']],
                        ['type' => 'feature', 'data' => ['icon' => 'heart', 'title' => 'Real users']],
                    ]],
                ]],
            ],
        ])->assertOk()->assertSee('Page created');

        $page = Page::query()->whereSlug('accessibility-audits')->sole();

        $this->assertSame(PageStatus::Draft, $page->status);
        $this->assertSame('accessibility-audits', $page->slug);
        $this->assertTrue($page->author->is($author));
        $this->assertCount(5, BlockTree::hydrate($page->blocks));
        $this->assertSame(RevisionSource::Mcp, $page->revisions()->first()->source);
        $this->assertTrue(McpActivity::query()->where('tool', 'create_page')->where('succeeded', true)->exists());
    }

    public function test_an_author_cannot_publish_over_mcp(): void
    {
        $author = $this->userWithRole('Author');

        CmsServer::actingAs($author)->tool(CreatePage::class, ['title' => 'Sneaky', 'status' => 'published'])
            ->assertHasErrors(['Permission denied']);

        $this->assertDatabaseMissing('pages', ['title' => 'Sneaky']);

        $page = Page::factory()->create(['author_id' => $author->id]);

        CmsServer::actingAs($author)->tool(UpdatePage::class, ['page' => $page->id, 'status' => 'published'])
            ->assertHasErrors(['Permission denied']);

        // Tools a role cannot use do not exist for that user at all.
        CmsServer::actingAs($author)->tool(PublishPage::class, ['page' => $page->id, 'action' => 'publish'])
            ->assertHasErrors(['not found']);

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
    }

    public function test_an_author_cannot_edit_someone_elses_page(): void
    {
        $author = $this->userWithRole('Author');
        $page = Page::factory()->create(['author_id' => $this->userWithRole('Editor')->id]);

        CmsServer::actingAs($author)->tool(UpdatePage::class, ['page' => $page->slug, 'title' => 'Mine now'])
            ->assertHasErrors(['Permission denied']);

        $this->assertNotSame('Mine now', $page->refresh()->title);
    }

    public function test_an_editor_can_publish(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create();

        CmsServer::actingAs($editor)->tool(PublishPage::class, ['page' => $page->id, 'action' => 'publish'])->assertOk();

        $this->assertTrue($page->refresh()->isLive());
    }

    public function test_a_user_without_the_mcp_permission_is_refused(): void
    {
        $viewer = $this->userWithRole('Viewer');

        CmsServer::actingAs($viewer)->tool(WhoAmI::class)->assertHasErrors(['not allowed to use the external AI app (MCP)']);
    }

    public function test_menu_tools_are_refused_without_the_menu_permission(): void
    {
        $author = $this->userWithRole('Author');

        CmsServer::actingAs($author)->tool(AddMenuItem::class, ['menu' => 'header', 'title' => 'Hi', 'url' => '/'])
            ->assertHasErrors(['not found']);
    }

    public function test_invalid_blocks_are_rejected_with_a_helpful_message(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create();

        CmsServer::actingAs($editor)->tool(SetPageBlocks::class, ['page' => $page->id, 'blocks' => [
            ['type' => 'button', 'data' => ['variant' => 'gigantic', 'colour' => 'red']],
            ['type' => 'unicorn'],
            ['type' => 'heading', 'children' => [['type' => 'text']]],
        ]])->assertHasErrors([
            '"button.variant" must be one of: primary, secondary, outline, ghost, link.',
            '"button" has no field "colour"',
            'unknown block type "unicorn"',
        ]);
    }

    public function test_blocks_can_be_added_and_updated_by_id(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['blocks' => []]);

        CmsServer::actingAs($editor)->tool(AddBlock::class, ['page' => $page->id, 'block' => ['type' => 'grid', 'data' => ['columns' => '3']]])->assertOk();
        $grid = BlockTree::hydrate($page->refresh()->blocks)[0];

        CmsServer::actingAs($editor)->tool(AddBlock::class, ['page' => $page->id, 'parent_id' => $grid['id'], 'block' => ['type' => 'stat', 'data' => ['value' => '42']]])->assertOk();
        $stat = collect(BlockTree::hydrate($page->refresh()->blocks))->firstWhere('type', 'stat');

        $this->assertSame($grid['id'], $stat['parent']);
        $this->assertSame('items', $stat['slot']);

        CmsServer::actingAs($editor)->tool(UpdateBlock::class, ['page' => $page->id, 'block_id' => $stat['id'], 'data' => ['label' => 'Answers'], 'settings' => ['background' => 'muted']])->assertOk();
        $stat = collect(BlockTree::hydrate($page->refresh()->blocks))->firstWhere('type', 'stat');

        $this->assertSame('42', $stat['data']['value']);
        $this->assertSame('Answers', $stat['data']['label']);
        $this->assertSame('muted', $stat['settings']['background']);

        CmsServer::actingAs($editor)->tool(GetPage::class, ['page' => $page->slug])->assertOk()->assertSee($stat['id']);
    }

    public function test_the_block_catalogue_describes_fields_and_options(): void
    {
        CmsServer::actingAs($this->userWithRole('Author'))->tool(ListBlockTypes::class, ['type' => 'hero'])
            ->assertOk()
            ->assertSee(['headline', 'centered', 'split', 'cover', 'primary_page']);
    }

    public function test_tools_show_for_the_local_user_of_a_stdio_connection(): void
    {
        $editor = $this->userWithRole('Editor');
        config(['services.mcp.local_user' => $editor->email]);

        $this->assertTrue(app(GetMenus::class)->shouldRegister(new McpRequest([])));
        $this->assertTrue(app(PublishPage::class)->shouldRegister(new McpRequest([])));
    }
}
