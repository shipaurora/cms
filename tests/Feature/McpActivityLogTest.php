<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\McpActivities\Pages\ListMcpActivities;
use Aurora\Models\McpActivity;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class McpActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_arguments_are_shown_as_a_code_block(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $withArguments = McpActivity::query()->create(['user_id' => $admin->id, 'tool' => 'get_page', 'arguments' => ['page' => 'pricing']]);
        $withoutArguments = McpActivity::query()->create(['user_id' => $admin->id, 'tool' => 'whoami', 'arguments' => []]);

        Livewire::actingAs($admin)
            ->test(ListMcpActivities::class)
            ->mountTableAction('view', $withArguments->getKey())
            ->assertMountedActionModalSeeHtml('<pre')
            ->assertMountedActionModalSee('"page": "pricing"');

        Livewire::actingAs($admin)
            ->test(ListMcpActivities::class)
            ->mountTableAction('view', $withoutArguments->getKey())
            ->assertMountedActionModalSee('No arguments');
    }

    public function test_each_site_shows_only_its_own_ai_activity(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        $here = McpActivity::query()->create(['user_id' => $admin->id, 'tool' => 'get_page']);
        $there = CurrentSite::using($shop, fn () => McpActivity::query()->create(['user_id' => $admin->id, 'tool' => 'update_page', 'arguments' => ['draft' => 'Secret launch copy']]));

        Livewire::actingAs($admin)
            ->test(ListMcpActivities::class)
            ->assertCanSeeTableRecords([$here])
            ->assertCanNotSeeTableRecords([$there]);
    }
}
