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
            .'@scope (.fpb-canvas){:scope.dark .hero,.card{ color: red; }}'
            .'@media (min-width: 40rem){@scope (.fpb-canvas){.card::after{ content: "{;}"; }}}'
            .'@keyframes fade{ from { opacity: 0; } }'
            .'@font-face{ font-family: Inter; }',
            $css,
        );
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
            ->assertContent('@scope (.fpb-canvas){:scope{color:red}}');
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
