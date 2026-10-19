<?php

use Aurora\Http\Controllers\AssetFileController;
use Aurora\Http\Controllers\AssistantFileController;
use Aurora\Http\Controllers\AuthenticateWithPasskeyController;
use Aurora\Http\Controllers\BrandImageController;
use Aurora\Http\Controllers\CanvasStylesController;
use Aurora\Http\Controllers\DemoLoginController;
use Aurora\Http\Controllers\FormSubmissionController;
use Aurora\Http\Controllers\SubmissionFileController;
use Aurora\Http\Controllers\ThemePreviewController;
use Aurora\Http\Controllers\UnlockPageController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController;

// Aurora's own public routes: forms, files, passkey sign-in and demo mode. The website's
// pages (and the catch-all that finds them) are the app's.
Route::middleware('web')->group(function (): void {
    Route::post('forms/{form}', FormSubmissionController::class)
        ->middleware('throttle:10,1')
        ->name('forms.submit');

    // The password form of a password-protected page.
    Route::post('pages/{page}/unlock', UnlockPageController::class)
        ->middleware('throttle:10,1')
        ->whereUuid('page')
        ->name('pages.unlock');

    // WebAuthn ceremonies for "Sign in with a passkey" (spatie/laravel-passkeys), signing in
    // through our controller: people with an authenticator app still enter its code.
    Route::prefix('passkeys')->group(function () {
        Route::get('authentication-options', GeneratePasskeyAuthenticationOptionsController::class)->name('passkeys.authentication_options');
        Route::post('authenticate', AuthenticateWithPasskeyController::class)->name('passkeys.login');
    });

    // A file sent through a form: for signed-in people who may see submissions, by signed link.
    Route::get('submission-files/{media}', SubmissionFileController::class)->middleware(['auth', 'signed'])->whereUuid('media')->name('submission-files.show');

    // The website's stylesheet, scoped to the visual editor's canvas.
    Route::get('aurora/canvas.css', CanvasStylesController::class)->middleware('auth')->name('aurora.canvas-styles');

    // A theme's picture, for choosing it under Settings › Appearance.
    Route::get('aurora/themes/{theme}/preview', ThemePreviewController::class)->middleware('auth')->where('theme', '[a-z0-9-]+')->name('aurora.themes.preview');

    // A media library file's lasting address: links to it survive the file being replaced.
    Route::get('files/{asset}', AssetFileController::class)->whereUuid('asset')->name('files.show');

    // A picture attached in the assistant, before it is saved into the media library.
    Route::get('assistant-files/{file}', AssistantFileController::class)
        ->middleware('auth')
        ->where('file', 'tmp_[0-9a-hjkmnp-tv-z]{26}')
        ->name('assistant-files.show');

    if (config('aurora.demo_login')) {
        Route::get('_demo/login/{email}', DemoLoginController::class)->name('demo.login');
    }

    // Aurora's mark, for the admin and its mails (BrandImageController).
    Route::get('aurora/brand/{file}', BrandImageController::class)->name('aurora.brand');
});
