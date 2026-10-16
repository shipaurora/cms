<?php

namespace Aurora\Http\Controllers;

use Aurora\Models\Page;
use Aurora\Support\PagePassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Receives the password form of a password-protected page: right, and the visitor goes back
 * to the page, open for the rest of their visit; wrong, and the form says so. Rate limited,
 * so passwords can't be guessed by trying them all.
 */
class UnlockPageController
{
    public function __invoke(Request $request, Page $page): RedirectResponse
    {
        $password = $request->input('password');

        if (! is_string($password) || ! PagePassword::unlock($page, $password)) {
            return redirect()->back()->withErrors(['password' => __('That password isn’t right.')], 'page_password');
        }

        return redirect()->back();
    }
}
