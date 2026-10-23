<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\User;
use Aurora\Support\CanvasStyles;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class CanvasStylesTest extends TestCase
{
    use RefreshDatabase;

    protected string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = sys_get_temp_dir().'/aurora-canvas-'.uniqid();
        File::ensureDirectoryExists($this->public.'/build/assets');
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->public);

        parent::tearDown();
    }

    public function test_the_website_s_rules_apply_only_inside_the_canvas(): void
    {
        $css = CanvasStyles::scope(<<<'CSS'
            /* Tailwind */
            @layer theme, base;
            @layer theme { :root, :host { --font-body: Inter; } }
            html.dark body > .hero, .card { color: red; }
            @media (min-width: 40rem) { .card::after { content: "{;}"; } }
            @keyframes fade { from { opacity: 0; } }
            @font-face { font-family: Inter; }
            CSS);

        $this->assertSame(
            '@layer theme, base;'
            .'@layer theme{@scope (.fpb-canvas){:scope{ --font-body: Inter; }}}'
            .'@scope (.fpb-canvas){:scope.dark > .hero,.card{ color: red; }}'
            .'@container fpb-canvas (min-width: 40rem){@scope (.fpb-canvas){.card::after{ content: "{;}"; }}}'
            .'@keyframes fade{ from { opacity: 0; } }'
            .'@font-face{ font-family: Inter; }',
            $css,
        );
    }

    public function test_the_canvas_is_painted_and_sized_as_the_site_s_page(): void
    {
        $css = CanvasStyles::scope(<<<'CSS'
            @layer base { html, body { background-color: var(--color-zinc-950); color: #fafafa } }
            .fpb-canvas > .fpb-block + .fpb-block { margin-top: 2rem }
            body > .fpb-block { padding: 1rem }
            .text-\[9vw\] { font-size: 9vw; width: calc(100svw - 2rem) }
            @media (width >= 48rem) { .md\:flex { display: flex } }
            @media screen and (min-width: 40rem) and (max-width: 64rem), (width < 20rem) { .a { color: red } }
            @media (prefers-color-scheme: dark) { .b { color: white } }
            @media print { .c { display: none } }
            @media (min-width: 40rem) and (hover: hover) { .d { color: red } }
            CSS);

        // The page's colours are the canvas's, though they're in a layer.
        $this->assertStringContainsString(':scope{ background-color: var(--color-zinc-950); color: #fafafa ;--fpb-canvas-bg:var(--color-zinc-950);--fpb-canvas-fg:#fafafa;}', $css);
        // The canvas by name is the scope, and combinators are kept.
        $this->assertStringContainsString(':scope > .fpb-block + .fpb-block{', $css);
        $this->assertStringContainsString(':scope > .fpb-block{', $css);
        // Widths of the window are the canvas's, in values only.
        $this->assertStringContainsString('.text-\[9vw\]{ font-size: 9cqw; width: calc(100cqw - 2rem) }', $css);
        $this->assertStringContainsString('@container fpb-canvas (width >= 48rem){', $css);
        $this->assertStringContainsString('@container fpb-canvas (min-width: 40rem) and (max-width: 64rem) or (width < 20rem){', $css);
        // Anything else asked of the window stays a media query.
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){', $css);
        $this->assertStringContainsString('@media print{', $css);
        $this->assertStringContainsString('@media (min-width: 40rem) and (hover: hover){', $css);
    }

    public function test_the_canvas_loads_the_website_s_built_stylesheet_scoped_to_it(): void
    {
        File::put($this->public.'/build/manifest.json', json_encode(['resources/css/app.css' => ['file' => 'assets/app-abc123.css']]));
        File::put($this->public.'/build/assets/app-abc123.css', 'body{color:red}');

        $url = CanvasStyles::url();
        $this->assertSame(route('aurora.canvas-styles', ['v' => 'assets/app-abc123.css']), $url);
        $this->assertStringContainsString($url, view('aurora::filament.page-builder.canvas-styles')->render());

        $this->get($url)->assertRedirect();

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
            ->assertContent('@scope (.fpb-canvas){:scope{color:red;--fpb-canvas-fg:red;}}');
    }

    public function test_while_vite_runs_the_canvas_takes_the_stylesheet_from_the_dev_server(): void
    {
        File::put($this->public.'/hot', 'http://localhost:5173');
        Http::fake(['localhost:5173/resources/css/app.css?direct' => Http::response('.logo{background:url("/resources/logo.svg")}')]);

        $this->assertSame(route('aurora.canvas-styles', ['v' => 'dev']), CanvasStyles::url());

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->get(CanvasStyles::url())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')
            ->assertContent('@scope (.fpb-canvas){.logo{background:url("http://localhost:5173/resources/logo.svg")}}');
    }

    public function test_the_canvas_gets_the_fonts_laravel_s_vite_loads_in_the_layout(): void
    {
        File::put($this->public.'/build/fonts-manifest.json', json_encode([
            'version' => 1,
            'style' => ['file' => 'assets/fonts.css'],
            'families' => ['instrument-sans' => ['family' => 'Instrument Sans']],
            'preloads' => [],
        ]));
        File::put($this->public.'/build/assets/fonts.css', '@font-face { font-family: "Instrument Sans"; }');

        $this->assertStringContainsString('font-family: "Instrument Sans"', view('aurora::filament.page-builder.canvas-styles')->render());
    }

    public function test_without_a_built_stylesheet_the_canvas_shows_blocks_without_it(): void
    {
        $this->assertNull(CanvasStyles::url());
        $this->assertStringNotContainsString('canvas.css', view('aurora::filament.page-builder.canvas-styles')->render());

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->get(route('aurora.canvas-styles'))
            ->assertNotFound();
    }
}
