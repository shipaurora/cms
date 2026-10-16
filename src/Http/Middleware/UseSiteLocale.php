<?php

namespace Aurora\Http\Middleware;

use Aurora\Models\Locale;
use Aurora\Support\ContentLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website speaks the language of its address: the default site language without a
 * prefix (/about-us), another language with its prefix in front (/nb/om-oss). The admin
 * panel then switches to each person's own language (UsePreferredLocale).
 */
class UseSiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = static::codeIn($request->path());

        ContentLocale::set($code);
        app()->setLocale($code ?? ContentLocale::default());

        return $next($request);
    }

    /**
     * The language whose prefix an address starts with, if it is one of the other languages
     * (its code, which the prefix may differ from: /no for "nb").
     */
    public static function codeIn(string $path): ?string
    {
        $first = strtolower(explode('/', trim($path, '/'))[0] ?? '');

        foreach (array_keys(ContentLocale::translations()) as $code) {
            if (Locale::prefixFor($code) === $first) {
                return $code;
            }
        }

        return null;
    }
}
