<?php

namespace Aurora\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saves the admin theme (light, dark or system) on the signed-in user, so it follows
 * them to every browser and device. Called by resources/js/admin/theme.js.
 */
class ThemePreferenceController
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['theme' => ['required', 'in:light,dark,system']]);

        $request->user()->forceFill(['theme' => $validated['theme']])->save();

        return response()->noContent();
    }
}
