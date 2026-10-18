<?php

use Aurora\Http\Controllers\Api\ListPagesController;
use Aurora\Http\Controllers\Api\ShowMenuController;
use Aurora\Http\Controllers\Api\ShowPageByPathController;
use Aurora\Http\Controllers\Api\ShowPageController;
use Aurora\Http\Controllers\Api\ShowSettingsController;
use Aurora\Http\Middleware\AllowContentApi;
use Aurora\Http\Middleware\ResolveSite;
use Aurora\Support\ContentApi;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

// The read-only content API (Aurora\Support\ContentApi): off until switched on in
// Settings › Integrations. The site is the one the request's domain belongs to.
Route::prefix(ContentApi::PREFIX)
    ->middleware([ResolveSite::class, 'throttle:120,1', SubstituteBindings::class, AllowContentApi::class])
    ->name('aurora.api.')
    ->group(function (): void {
        Route::get('pages', ListPagesController::class)->name('pages');
        Route::get('pages/{page}', ShowPageController::class)->whereUuid('page')->name('pages.show');
        Route::get('page', ShowPageByPathController::class)->name('page');
        Route::get('menus/{location}', ShowMenuController::class)->name('menus.show');
        Route::get('settings', ShowSettingsController::class)->name('settings');
    });
