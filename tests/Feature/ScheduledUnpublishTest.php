<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class ScheduledUnpublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_comes_down_by_itself_at_its_unpublish_date(): void
    {
        Page::factory()->homepage()->create();
        $page = Page::factory()->published()->create(['slug' => 'summer-sale', 'unpublish_at' => now()->addHour()]);

        $this->get('/summer-sale')->assertOk();
        $this->assertTrue($page->isLive());

        $this->travel(2)->hours();

        $this->get('/summer-sale')->assertNotFound();
        $this->assertTrue($page->fresh()->hasEnded());
        $this->assertFalse(Page::query()->live()->whereKey($page->id)->exists());
        $this->assertTrue(Page::query()->ended()->whereKey($page->id)->exists());
        $this->get('/sitemap.xml')->assertDontSee('summer-sale');
    }

    public function test_it_is_set_in_the_form_after_the_publish_date(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'job']);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertFormFieldVisible('unpublish_at')
            ->fillForm(['unpublish_at' => now()->subDays(5)])
            ->call('save')
            ->assertHasFormErrors(['unpublish_at'])
            ->fillForm(['unpublish_at' => now()->addWeek()->startOfMinute()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($page->fresh()->unpublish_at->isFuture());
    }

    public function test_the_homepage_can_t_come_down_by_itself(): void
    {
        $home = Page::factory()->homepage()->create();

        $this->expectException(ValidationException::class);
        $home->update(['unpublish_at' => now()->addDay()]);
    }

    public function test_ai_apps_set_and_clear_it(): void
    {
        Page::factory()->homepage()->create();
        $page = Page::factory()->published()->create();
        $editor = $this->userWithRole('Editor');

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => $page->id, 'unpublish_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertNotNull($page->fresh()->unpublish_at);

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => $page->id, 'unpublish_at' => ''])->assertOk();
        $this->assertNull($page->fresh()->unpublish_at);
    }
}
