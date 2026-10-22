<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Livewire\EditLock;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Page;
use Aurora\Support\EditLocks;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class EditLocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_page_locks_it_for_others_who_see_who_is_editing(): void
    {
        $page = Page::factory()->create(['title' => 'Prices']);
        $ingrid = $this->userWithRole('Editor');
        $ola = $this->userWithRole('Editor');

        Livewire::actingAs($ingrid)->test(EditLock::class, ['record' => $page])->assertSet('held', true)->assertDontSee('is editing this page');

        Livewire::actingAs($ola)
            ->test(EditLock::class, ['record' => $page])
            ->assertSet('held', false)
            ->assertSee($ingrid->name.' is editing this page')
            ->assertSee('Take over');

        // Ola's save is refused, Ingrid's goes through.
        Livewire::actingAs($ola)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Ola was here'])
            ->call('save')
            ->assertNotified($ingrid->name.' is editing this page');
        $this->assertSame('Prices', $page->fresh()->title);

        Livewire::actingAs($ingrid)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'New prices'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('New prices', $page->fresh()->title);
    }

    public function test_a_lock_nobody_pings_clears_by_itself(): void
    {
        $page = Page::factory()->create();
        $ingrid = $this->userWithRole('Editor');
        $ola = $this->userWithRole('Editor');

        EditLocks::ping($page, $ingrid);
        $this->assertTrue(EditLocks::heldByOther($page, $ola));

        // Ingrid's screen keeps pinging: it lasts.
        $this->travel(EditLocks::TTL - 10)->seconds();
        EditLocks::ping($page, $ingrid);
        $this->travel(EditLocks::TTL - 10)->seconds();
        $this->assertTrue(EditLocks::heldByOther($page, $ola));

        // Then she closes the tab: no more pings, and the lock goes.
        $this->travel(EditLocks::TTL + 1)->seconds();
        $this->assertNull(EditLocks::holder($page));
        $this->assertSame($ola->getKey(), EditLocks::ping($page, $ola)['user_id']);
    }

    public function test_someone_can_take_over_and_leaving_lets_it_go(): void
    {
        $page = Page::factory()->create();
        $ingrid = $this->userWithRole('Editor');
        $ola = $this->userWithRole('Editor');

        $ingridsLock = Livewire::actingAs($ingrid)->test(EditLock::class, ['record' => $page]);
        Livewire::actingAs($ola)->test(EditLock::class, ['record' => $page])->call('takeOver')->assertSet('held', true);

        $this->assertTrue(EditLocks::heldByOther($page, $ingrid));
        $this->actingAs($ingrid);
        $ingridsLock->call('ping')->assertSet('held', false)->assertSee($ola->name.' is editing this page');

        // Ola leaves: it's free.
        Livewire::actingAs($ola)->test(EditLock::class, ['record' => $page])->call('release');
        $this->assertNull(EditLocks::holder($page));
    }

    public function test_ai_apps_don_t_save_over_someone_editing(): void
    {
        $page = Page::factory()->create(['title' => 'Prices']);
        EditLocks::ping($page, $this->userWithRole('Editor'));

        CmsServer::actingAs($this->userWithRole('Editor'))
            ->tool(UpdatePage::class, ['page' => $page->id, 'title' => 'AI'])
            ->assertHasErrors(['is editing this page right now']);
        $this->assertSame('Prices', $page->fresh()->title);
    }
}
