<?php

namespace Aurora\Http\Middleware;

use Aurora\Models\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel speaks each person's preferred language (their profile), or the default
 * language when they haven't picked one or theirs has been switched off.
 */
class UsePreferredLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->user()?->locale;

        app()->setLocale(Locale::isActiveCode($preferred) ? $preferred : Locale::defaultCode());

        // Numbers too: "1 002" in Norwegian, "1,002" in English.
        Number::useLocale(app()->getLocale());

        return $next($request);
    }
}
