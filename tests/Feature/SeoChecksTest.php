<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\SeoReport;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\SeoChecks;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class SeoChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function messages(Page $page): string
    {
        return implode("\n", array_column(SeoChecks::for($page), 'message'));
    }

    public function test_a_page_hears_what_would_help_it_in_search(): void
    {
        $page = Page::factory()->published()->create([
            'title' => str_repeat('Very long title ', 6),
            'excerpt' => null,
            'blocks' => BlockTreeBuilder::flatten([
                ['type' => 'heading', 'data' => ['text' => 'Only an H2', 'level' => 'h2']],
                ['type' => 'image', 'data' => ['src' => 'demo/fjord.jpg']],
            ]),
        ]);

        $messages = $this->messages($page);

        $this->assertStringContainsString('No meta description or summary', $messages);
        $this->assertStringContainsString('search results cut it off', $messages);
        $this->assertStringContainsString('No main heading', $messages);
        $this->assertStringContainsString('1 image has no alt text', $messages);
        // The worst first.
        $this->assertSame('danger', SeoChecks::for($page)[0]['level']);
    }

    public function test_a_well_made_page_has_nothing_to_fix_but_shared_titles_are_flagged(): void
    {
        $good = Page::factory()->published()->create([
            'title' => 'Opening hours',
            'meta_description' => 'When the café in Bergen is open, on weekdays and weekends, and on public holidays too.',
            'og_image' => 'demo/fjord.jpg',
            'blocks' => BlockTreeBuilder::flatten([['type' => 'hero', 'data' => ['headline' => 'Opening hours']]]),
        ]);

        $this->assertSame([], SeoChecks::for($good));

        Page::factory()->published()->create(['title' => 'Opening hours']);
        $this->assertStringContainsString('Another page has the same title', $this->messages($good->fresh()));
    }

    public function test_the_report_lists_pages_that_need_work_in_settings_and_for_ai_apps(): void
    {
        Page::factory()->published()->create(['title' => 'Needs work', 'excerpt' => null]);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'search-seo', 'section' => 'seo'])
            ->test(SiteSettings::class)
            ->assertSee('Needs work')
            ->assertSee('No meta description or summary');

        CmsServer::actingAs($this->userWithRole('Editor'))
            ->tool(SeoReport::class)
            ->assertOk()
            ->assertSee(['Needs work', 'No meta description or summary']);
    }
}
