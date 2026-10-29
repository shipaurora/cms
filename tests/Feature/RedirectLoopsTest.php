<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Redirects\Pages\ManageRedirects;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Support\PageRedirects;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class RedirectLoopsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_redirect_that_would_send_visitors_round_in_a_circle_is_not_saved(): void
    {
        Redirect::query()->create(['from_path' => '/a', 'to_url' => '/b', 'status_code' => 301]);
        Redirect::query()->create(['from_path' => '/b', 'to_url' => 'https://elsewhere.example/b', 'status_code' => 301]);
        Redirect::query()->create(['from_path' => '/x', 'to_url' => '/y', 'status_code' => 301]);
        Redirect::query()->create(['from_path' => '/y', 'to_url' => '/z', 'status_code' => 301]);

        foreach ([['/z', '/x', '/z → /x → /y → /z'], ['/c', '/c', '/c → /c'], ['/d', url('/d'), '/d → /d']] as [$from, $to, $circle]) {
            try {
                Redirect::query()->create(['from_path' => $from, 'to_url' => $to, 'status_code' => 301]);
                $this->fail("{$from} → {$to} was saved.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($circle, $exception->errors()['from_path'][0]);
            }
        }

        // Changing one into a circle is refused as well.
        $this->expectException(ValidationException::class);
        Redirect::query()->where('from_path', '/b')->sole()->update(['to_url' => '/a']);
    }

    public function test_a_live_page_ends_the_way(): void
    {
        Page::factory()->published()->create(['slug' => 'b']);
        Redirect::query()->create(['from_path' => '/a', 'to_url' => '/b', 'status_code' => 301]);

        // From the page's own address: never asked while the page is there.
        Redirect::query()->create(['from_path' => '/b', 'to_url' => '/a', 'status_code' => 301]);

        $this->get('/a')->assertRedirect('/b');
        $this->get('/b')->assertOk();
    }

    public function test_a_circle_that_comes_about_later_is_not_followed(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'p']);
        Redirect::query()->create(['from_path' => '/x', 'page_id' => $page->id, 'status_code' => 301]);
        Redirect::query()->create(['from_path' => '/p', 'to_url' => '/x', 'status_code' => 301]);

        // The page is no longer live: its address doesn't answer, and /x → /p → /x would go round.
        Page::query()->whereKey($page->id)->update(['status' => PageStatus::Draft]);

        $this->get('/x')->assertNotFound();
        $this->get('/p')->assertNotFound();
    }

    public function test_a_page_back_at_an_address_that_redirected_to_it_loses_that_redirect(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'about']);

        $page->update(['slug' => 'about-us']);
        PageRedirects::keep($page, ['/about']);
        $this->get('/about')->assertRedirect('/about-us');

        $page->update(['slug' => 'about']);

        $this->assertFalse(Redirect::query()->where('from_path', '/about')->exists());
        $this->get('/about')->assertOk();
    }

    public function test_the_redirects_screen_says_so_before_saving(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        Redirect::query()->create(['from_path' => '/a', 'to_url' => url('/b'), 'status_code' => 301]);

        Livewire::test(ManageRedirects::class)
            ->callAction(TestAction::make('create'), data: ['from_path' => '/b', 'target' => 'url', 'to_url' => url('/a'), 'status_code' => 301])
            ->assertHasFormErrors(['from_path']);

        $this->assertFalse(Redirect::query()->where('from_path', '/b')->exists());
    }
}
