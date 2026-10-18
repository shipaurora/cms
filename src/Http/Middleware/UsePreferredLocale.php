<?php

namespace Aurora\Http\Middleware;

use Aurora\Models\Locale;
use Aurora\Support\ContentLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel speaks each person's preferred language (their profile), or the site's
 * language when they haven't picked one or theirs has been switched off.
 */
class UsePreferredLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        static::apply();

        return $next($request);
    }

    /**
     * Apply it now. The site is known only after its middleware, so that middleware applies
     * it again: a person who hasn't picked a language then gets that site's language.
     */
    public static function apply(): void
    {
        $preferred = auth()->user()?->locale;

        app()->setLocale(Locale::isActiveCode($preferred) ? $preferred : ContentLocale::default());

        // Numbers too: "1 002" in Norwegian, "1,002" in English.
        Number::useLocale(app()->getLocale());
    }
}
