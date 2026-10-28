<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

class PageRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_a_package_registers_after_aurora_still_win_over_its_pages(): void
    {
        $about = Page::factory()->published()->create(['slug' => 'about', 'title' => 'About us']);
        Page::factory()->published()->homepage()->create(['title' => 'Welcome home']);

        // As Statamic does once the app has booted, after Aurora: its control panel, and its website.
        Route::middleware('web')->get('cp/{path?}', fn (): string => 'Statamic control panel')->where('path', '.*');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/cp/auth/login')->assertOk()->assertSee('Statamic control panel');
        $this->get('/about')->assertOk()->assertSee($about->title);
        $this->get('/')->assertOk()->assertSee('Welcome home');

        // A catch-all website of its own (Statamic's, until statamic.routes.enabled is off) serves every address.
        Route::middleware('web')->any('/{segments?}', fn (): string => 'Statamic website')->where('segments', '.*');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/about')->assertSee('Statamic website');
        $this->get('/')->assertSee('Statamic website');
    }
}
