<?php

namespace Aurora\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saves whether the admin sidebar is collapsed on the signed-in user, so it follows them to
 * every browser and device. Called by resources/js/admin/sidebar.js.
 */
class SidebarPreferenceController
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['collapsed' => ['required', 'boolean']]);

        $request->user()->forceFill(['sidebar_collapsed' => $validated['collapsed']])->save();

        return response()->noContent();
    }
}
