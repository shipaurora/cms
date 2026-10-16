<?php

use Aurora\Http\Controllers\PageController;
use Aurora\Models\Page;
use Illuminate\Support\Facades\Route;

// The website's pages, registered after the app's own routes (AuroraServiceProvider), so a
// site's routes always win and the catch-all comes last. config('aurora.routes') false: none.
Route::middleware('web')->group(function (): void {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('sitemap.xml', [PageController::class, 'sitemap'])->name('sitemap');
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
        ->where('slug', '(?!(?:admin|livewire|filament|oauth|mcp|api|storage|up)(?:/|$))[A-Za-z0-9\-_/.]+')
        ->name('pages.show');
});
