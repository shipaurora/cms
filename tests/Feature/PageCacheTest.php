<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\PageCache;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class PageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put([PageCache::SETTING => ['enabled' => true, 'minutes' => 60]]);
        Page::factory()->homepage()->create();
    }

    public function test_visitors_get_a_page_ready_made_until_something_changes(): void
    {
        $page = Page::factory()->published()->create(['title' => 'Prices', 'slug' => 'prices']);

        $this->get('/prices')->assertOk()->assertHeader('X-Aurora-Cache', 'MISS');
        $this->get('/prices')->assertOk()->assertHeader('X-Aurora-Cache', 'HIT')->assertSee('Prices');

        // A change in the CMS clears it.
        $page->update(['title' => 'New prices']);
        $this->get('/prices')->assertHeader('X-Aurora-Cache', 'MISS')->assertSee('New prices');
    }

    public function test_some_pages_and_visitors_always_get_it_fresh(): void
    {
        Page::factory()->published()->create(['slug' => 'members', 'password' => 'open sesame']);
        Page::factory()->published()->create(['slug' => 'contact', 'blocks' => BlockTreeBuilder::flatten([
            ['type' => 'form', 'data' => ['form_id' => Form::factory()->create()->id]],
        ])]);
        Page::factory()->published()->create(['slug' => 'open']);

        $this->get('/members')->assertHeaderMissing('X-Aurora-Cache');
        $this->get('/contact');
        $this->get('/contact')->assertHeaderMissing('X-Aurora-Cache');
        $this->get('/open?utm=1')->assertHeaderMissing('X-Aurora-Cache');

        $this->actingAs($this->userWithRole('Editor'))->get('/open')->assertHeaderMissing('X-Aurora-Cache');
    }

    public function test_a_scheduled_page_goes_live_on_time(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'sale', 'unpublish_at' => now()->addMinutes(5)]);

        $this->get('/sale')->assertHeader('X-Aurora-Cache', 'MISS');
        $this->travel(6)->minutes();
        $this->get('/sale')->assertNotFound();
    }

    public function test_it_is_switched_on_and_cleared_in_settings(): void
    {
        Setting::put([PageCache::SETTING => ['enabled' => false]]);
        Page::factory()->published()->create(['slug' => 'prices']);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'performance'])
            ->test(SiteSettings::class)
            ->fillForm([PageCache::SETTING => ['enabled' => true, 'minutes' => 30]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(PageCache::enabled());
        $this->assertSame(30, PageCache::minutes());

        auth()->logout();
        $this->get('/prices');
        $this->get('/prices')->assertHeader('X-Aurora-Cache', 'HIT');
        PageCache::flush();
        $this->get('/prices')->assertHeader('X-Aurora-Cache', 'MISS');
    }
}
