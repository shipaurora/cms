<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\Dashboard;
use Aurora\Filament\Widgets\WelcomeWidget;
use Aurora\Models\McpActivity;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Livewire\Livewire;

class WelcomeWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_has_no_heading_but_keeps_its_title(): void
    {
        $this->actingAs($this->userWithRole('Editor'))
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('<title>', false)
            ->assertDontSee('fi-header-heading', false);
    }

    public function test_it_suggests_connecting_an_ai_until_one_is_connected(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Personal access tokens', 'users');
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(WelcomeWidget::class)
            ->assertSee('Connect your AI')
            ->assertDontSee('AI activity');

        $editor->createToken('Claude Code', ['mcp:use']);

        Livewire::actingAs($editor)
            ->test(WelcomeWidget::class)
            ->assertDontSee('Connect your AI')
            ->assertSee('AI activity')
            ->assertSee('Claude Code is connected');

        McpActivity::query()->create(['user_id' => $editor->id, 'tool' => 'update_page', 'succeeded' => true]);

        Livewire::actingAs($editor)
            ->test(WelcomeWidget::class)
            ->assertSee('Last action');
    }
}
