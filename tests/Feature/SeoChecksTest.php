<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\SeoReport;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Models\User;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\RenderedPage;
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
        // The site's logo links home.
        Page::factory()->homepage()->published()->create();
        $good = Page::factory()->published()->create([
            'title' => 'Opening hours at the café in Bergen',
            'meta_description' => 'When the café in Bergen is open, on weekdays and weekends, and on public holidays too.',
            'og_image' => 'demo/fjord.jpg',
            'blocks' => BlockTreeBuilder::flatten([
                ['type' => 'hero', 'data' => ['headline' => 'Opening hours']],
                ['type' => 'rich-text', 'data' => ['body' => '<h2>Weekdays</h2><p>'.str_repeat('We open early and close late. ', 60).'</p>']],
            ]),
        ]);

        $this->assertSame([], SeoChecks::for($good));

        Page::factory()->published()->create(['title' => 'Opening hours at the café in Bergen']);
        $this->assertStringContainsString('Another page has the same title', $this->messages($good->fresh()));
    }

    public function test_headings_and_links_are_checked_on_the_page_as_visitors_get_it(): void
    {
        Page::factory()->homepage()->published()->create();
        Page::factory()->published()->create(['title' => 'Prices', 'slug' => 'prices']);
        Page::factory()->create(['title' => 'Coming soon', 'slug' => 'coming-soon']);
        Redirect::query()->create(['from_path' => '/old-prices', 'to_url' => '/prices', 'status_code' => 301]);

        $page = Page::factory()->published()->create([
            'title' => 'Everything about our opening hours',
            'blocks' => BlockTreeBuilder::flatten([
                ['type' => 'hero', 'data' => ['headline' => 'Opening hours']],
                // A second H1 in rich text, and H2 straight to H4.
                ['type' => 'rich-text', 'data' => ['body' => '<h1>Also a title</h1><h2>Weekdays</h2><h4>Mornings</h4>'
                    .'<p><a href="/prices">Prices</a>, <a href="/coming-soon">soon</a>, <a href="/nowhere">gone</a>, <a href="/old-prices">old</a>, <a href="https://example.com/x">elsewhere</a></p>']],
            ]),
        ]);

        $issues = collect(SeoChecks::for($page))->pluck('level', 'message');

        $this->assertSame('warning', $issues['2 H1 headings: a page should have one, saying what it is about. Make the others H2.']);
        $this->assertSame('info', $issues['A heading skips a level (H2 to H4): search engines and screen readers follow headings as an outline.']);
        $this->assertSame('warning', $issues['2 links lead nowhere (pages that aren’t live, or were removed): /coming-soon, /nowhere']);
        $this->assertSame('info', $issues['1 link goes through a redirect: link to the page’s address itself. /old-prices']);
        $this->assertStringStartsWith('The page says little', (string) $issues->keys()->first(fn (string $message): bool => str_starts_with($message, 'The page says little')));

        // Only the warnings make the site's report.
        $this->assertSame(2, count(SeoChecks::report()[0]['issues']));
    }

    public function test_links_without_text_and_images_without_alt_are_read_from_the_markup(): void
    {
        $read = RenderedPage::read('<header><h1>Site</h1></header><main><h2>Shop</h2>'
            .'<a href="/a"></a><a href="/b"><svg></svg></a><a href="/c" aria-label="Cart"><svg></svg></a><a href="/d"><img src="x.png" alt="Logo"></a>'
            .'<img src="y.png"><img src="z.png" alt=""><script>document.write("<h1>Not counted</h1>")</script></main>');

        $this->assertSame(1, $read['h1']);
        // Headings in the page's content, not the layout's header.
        $this->assertSame([2], $read['headings']);
        $this->assertSame(2, $read['empty_links']);
        $this->assertSame(1, $read['images_without_alt']);
        $this->assertSame(['/a', '/b', '/c', '/d'], $read['links']);
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
