<?php

use Aurora\Http\Controllers\PageController;
use Aurora\Http\Middleware\CachePages;
use Aurora\Http\Middleware\RestrictWebsiteAccess;
use Aurora\Models\Page;
use Illuminate\Support\Facades\Route;

// The website's pages, registered after the app's own routes (AuroraServiceProvider), so a
// site's routes always win and the catch-all comes last. config('aurora.routes') false: none.
Route::middleware('web')->group(function (): void {
    // Open to everyone, or only some addresses (Settings › Security › Website access); kept
    // ready-made for visitors when the page cache is on (Settings › Performance).
    Route::get('/', [PageController::class, 'home'])->middleware([RestrictWebsiteAccess::class, CachePages::class])->name('home');
    Route::get('sitemap.xml', [PageController::class, 'sitemap'])->middleware(RestrictWebsiteAccess::class)->name('sitemap');
    Route::get('robots.txt', [PageController::class, 'robots'])->name('robots');

    // Signed, so it may show any page: drafts, scheduled pages, pages in the trash, on any site.
    Route::bind('previewPage', fn (string $id): Page => Page::acrossSites()->withTrashed()->findOrFail($id));
    Route::get('preview/{previewPage}', [PageController::class, 'preview'])
        ->middleware('signed')
        ->name('pages.preview');

    // Catch-all for pages. Dots are allowed so redirects can catch old file addresses
    // (/brochure.pdf); pages themselves have none. The application's own paths are never
    // pages: Filament registers some of its routes (the multi-site /admin/{site}) as fallbacks,
    // which would otherwise lose to this one.
    Route::get('{slug}', [PageController::class, 'show'])
        ->middleware([RestrictWebsiteAccess::class, CachePages::class])
        ->where('slug', '(?!(?:admin|livewire|filament|oauth|mcp|api|storage|up)(?:/|$))[A-Za-z0-9\-_/.]+')
        ->name('pages.show');
});
