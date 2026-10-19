<?php

use Aurora\Http\Controllers\Api\GraphqlController;
use Aurora\Http\Controllers\Api\ListMediaController;
use Aurora\Http\Controllers\Api\ListPagesController;
use Aurora\Http\Controllers\Api\ShowMediaController;
use Aurora\Http\Controllers\Api\ShowMenuController;
use Aurora\Http\Controllers\Api\ShowPageByPathController;
use Aurora\Http\Controllers\Api\ShowPageController;
use Aurora\Http\Controllers\Api\ShowSettingsController;
use Aurora\Http\Middleware\AllowApi;
use Aurora\Http\Middleware\ResolveSite;
use Aurora\Support\PublicApi;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

// The website's read-only APIs (Aurora\Support\PublicApi): each off until switched on in
// Settings › Integrations. The site is the one the request's domain belongs to.
Route::prefix(PublicApi::REST_PREFIX)
    ->middleware([ResolveSite::class, 'throttle:120,1', SubstituteBindings::class, AllowApi::class.':rest'])
    ->name('aurora.api.')
    ->group(function (): void {
        Route::get('pages', ListPagesController::class)->name('pages');
        Route::get('pages/{page}', ShowPageController::class)->whereUuid('page')->name('pages.show');
        Route::get('page', ShowPageByPathController::class)->name('page');
        Route::get('media', ListMediaController::class)->name('media');
        Route::get('media/{asset}', ShowMediaController::class)->whereUuid('asset')->name('media.show');
        Route::get('menus/{location}', ShowMenuController::class)->name('menus.show');
        Route::get('settings', ShowSettingsController::class)->name('settings');
    });

Route::match(['get', 'post'], PublicApi::GRAPHQL_PATH, GraphqlController::class)
    ->middleware([ResolveSite::class, 'throttle:120,1', AllowApi::class.':graphql'])
    ->name('aurora.graphql');
