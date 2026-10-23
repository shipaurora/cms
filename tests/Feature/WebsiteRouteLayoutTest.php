<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\Layouts;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use LogicException;

/**
 * A website route's own page in the site's layout (<x-aurora::layout>), as a page is drawn.
 */
class WebsiteRouteLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_website_route_s_content_is_drawn_in_the_default_layout(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-aurora::layout title="Events" description="What's on in Bergen this spring.">
                <ul class="events"><li>{{ current_page()->title }} in May</li></ul>
            </x-aurora::layout>
            BLADE);

        // The standard layout's head and header, with the route's title and description.
        $this->assertStringContainsString('<title>Events · ', $html);
        $this->assertStringContainsString('<meta name="description" content="What&#039;s on in Bergen this spring.">', $html);
        $this->assertStringContainsString('<header class="site-header">', $html);
        // Its content where a page's blocks would be; blocks ask current_page() as on a page.
        $this->assertMatchesRegularExpression('#<main>\s*<div class="fpb-page">\s*<ul class="events"><li>Events in May</li></ul>#', $html);
        $this->assertNull(View::shared('currentPage'));
    }

    public function test_another_layout_is_chosen_by_its_handle(): void
    {
        $views = sys_get_temp_dir().'/aurora-route-layout-'.uniqid();
        File::ensureDirectoryExists($views);
        File::put($views.'/bare.blade.php', '<body class="bare">{{ $page->seoTitle() }} {{ $settings["tone"] ?? "" }}<x-aurora::blocks :page="$page" /></body>');
        View::addNamespace('route-fixtures', $views);
        Layouts::put(null, ['name' => 'Bare', 'handle' => 'bare', 'view' => 'route-fixtures::bare']);

        $html = (string) Layouts::wrap(new HtmlString('<p>Albums</p>'), 'Albums', layout: 'bare', settings: ['tone' => 'dark']);

        $this->assertStringContainsString('<body class="bare">Albums dark<div class="fpb-page"><p>Albums</p></div>', $html);
        File::deleteDirectory($views);
    }

    public function test_the_stand_in_page_has_the_request_s_address_in_each_language_and_is_never_saved(): void
    {
        $this->withEnglishAndNorwegian();
        $this->app->instance('request', Request::create('/nb/events/2026'));
        ContentLocale::set('nb');

        $page = Page::standIn('Events', new HtmlString('…'));

        $this->assertTrue($page->isStandIn());
        $this->assertSame('/nb/events/2026', $page->path());
        $this->assertSame('/events/2026', $page->path('en'));

        $this->expectException(LogicException::class);
        $page->save();
    }
}
