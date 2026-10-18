<?php

namespace Aurora\Http\Middleware;

use Aurora\Models\Site;
use Aurora\Support\CurrentSite;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin: the site picked in the admin (Filament's tenant) is the one content is
 * read and written for, on every request, Livewire's included.
 */
class SetSiteFromTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Site) {
            CurrentSite::set($tenant);
        }

        // The preferred language ran before the site was known. Apply it again so someone
        // who hasn't picked one gets this site's language.
        if ($request->user() !== null) {
            UsePreferredLocale::apply();
        }

        return $next($request);
    }
}
