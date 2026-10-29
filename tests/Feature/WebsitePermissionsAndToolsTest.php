<?php

namespace Aurora\Tests\Feature;

use Aurora\Mcp\Servers\CmsServer;
use Aurora\Models\AssetTag;
use Aurora\Models\Permission;
use Aurora\Models\Role;
use Aurora\Support\CopilotTools;
use Aurora\Support\McpTools;
use Aurora\Support\Permissions;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\McpServerTool;
use Workbench\App\Mcp\Tools\ListTags;

class WebsitePermissionsAndToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_websites_permissions_join_the_roles_and_are_given_once_to_the_roles_named(): void
    {
        // A site already running, its roles set up before the website added its permissions.
        $editorUser = $this->userWithRole('Editor');
        $author = $this->userWithRole('Author');

        Permissions::register('Events', [
            'view-any Event' => ['See events', 'Open the event list.'],
            'update Event' => ['Edit events', 'Change any event.'],
        ], 'phosphor-calendar', 'The website\'s events.', grantTo: ['Editor']);

        try {
            $this->assertArrayHasKey('Events', Permissions::catalogue());
            $this->assertContains('update Event', Permissions::roles()['Editor']['permissions']);

            Permissions::sync();

            $this->assertTrue(Permission::query()->where('name', 'update Event')->exists());
            $editor = Role::findByName('Editor', 'web');
            $this->assertTrue($editor->hasPermissionTo('update Event'));
            $this->assertTrue($editorUser->fresh()->can('update Event'));
            $this->assertFalse($author->fresh()->can('update Event'));

            // An administrator takes it away: syncing again leaves the role as they left it.
            $editor->revokePermissionTo('update Event');
            Permissions::sync();
            $this->assertFalse($editor->fresh()->hasPermissionTo('update Event'));

            $this->artisan('aurora:sync-permissions')->assertSuccessful();
        } finally {
            Permissions::forget('Events');
        }
    }

    public function test_a_websites_tool_is_offered_to_ai_apps_and_the_assistant(): void
    {
        AssetTag::query()->create(['name' => 'Fjords']);
        $editor = $this->userWithRole('Editor');
        McpTools::register([ListTags::class]);

        try {
            CmsServer::actingAs($editor)->tool(ListTags::class)->assertOk()->assertSee('Fjords');
            CmsServer::actingAs($this->userWithRole('Viewer'))->tool(ListTags::class)->assertHasErrors();

            $this->actingAs($editor);
            $this->assertContains('list_tags', collect(CopilotTools::forCurrentUser())->map(fn (McpServerTool $tool): string => $tool->name())->all());
        } finally {
            McpTools::forget([ListTags::class]);
        }
    }
}
