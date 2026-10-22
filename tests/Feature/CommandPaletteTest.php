<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\PaletteCommands;
use Aurora\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Usamamuneerchaudhary\CommandPalette\Livewire\CommandPalette;
use Usamamuneerchaudhary\CommandPalette\Support\CommandItem;

class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, CommandItem>
     */
    protected function commands(): array
    {
        return collect(PaletteCommands::all())->keyBy(fn (CommandItem $item): string => $item->toArray()['label'])->all();
    }

    public function test_actions_follow_what_the_user_may_do(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        $admin = $this->commands();

        $this->assertArrayHasKey('Draft a new page', $admin);
        $this->assertArrayHasKey('Invite someone', $admin);
        $this->assertArrayHasKey('Create a redirect', $admin);
        $this->assertSame(UserResource::getUrl('index', ['action' => 'invite']), $admin['Invite someone']->toArray()['url']);

        $this->actingAs($this->userWithRole('Viewer'));
        $viewer = $this->commands();

        $this->assertArrayNotHasKey('Draft a new page', $viewer);
        $this->assertArrayNotHasKey('Invite someone', $viewer);
        $this->assertArrayHasKey('View the website', $viewer);
    }

    public function test_the_page_being_edited_gets_its_own_commands(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        $page = Page::factory()->create(['title' => 'Careers', 'status' => PageStatus::Draft]);

        request()->headers->set('referer', PageResource::getUrl('edit', ['record' => $page]));
        $commands = $this->commands();

        $this->assertArrayHasKey('Open in the visual editor', $commands);
        $this->assertArrayHasKey('Publish this page', $commands);
        $this->assertSame('This page', $commands['Publish this page']->toArray()['group']);
        $this->assertStringContainsString('action=publish', $commands['Publish this page']->toArray()['url']);

        request()->headers->set('referer', PageResource::getUrl('index'));

        $this->assertArrayNotHasKey('Publish this page', $this->commands());
    }

    public function test_the_palette_loads_the_cms_commands_with_navigation_but_without_generic_resource_commands(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        Filament::setCurrentPanel('admin');

        $labels = collect(Livewire::test(CommandPalette::class, ['panelId' => 'admin'])->instance()->loadCommands()['commands'])
            ->pluck('label');

        $this->assertContains('Draft a new page', $labels);
        $this->assertContains('Invite someone', $labels);
        $this->assertTrue($labels->contains(fn (string $label): bool => str_contains($label, 'Media')));
        $this->assertFalse($labels->contains(fn (string $label): bool => str_starts_with($label, 'Create ') && str_ends_with($label, 'role')));
    }
}
