<?php

namespace Aurora\Support;

use Aurora\Models\Locale;

/**
 * The language content is read and written in: page titles, addresses, block text. Separate
 * from the admin panel's language (each person's own): on the website it is the language
 * of the address (/en/…), in the admin the default site language unless someone is
 * translating into another.
 */
class ContentLocale
{
    /**
     * Kept in the container, so it lasts one request (or test) and never leaks into the next.
     */
    protected const KEY = 'cms.content-locale';

    public static function current(): string
    {
        return (app()->bound(self::KEY) ? app(self::KEY) : null) ?? static::default();
    }

    public static function set(?string $code): void
    {
        app()->instance(self::KEY, Locale::isActiveCode($code) ? $code : null);
    }

    /**
     * Run something in another content language, then switch back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?string $code, callable $callback): mixed
    {
        $previous = app()->bound(self::KEY) ? app(self::KEY) : null;
        static::set($code);

        try {
            return $callback();
        } finally {
            app()->instance(self::KEY, $previous);
        }
    }

    /**
     * The current site's default language, else the CMS's (Settings › Languages).
     */
    public static function default(): string
    {
        $site = CurrentSite::get()?->default_locale;

        return $site !== null && Locale::isActiveCode($site) ? $site : Locale::defaultCode();
    }

    public static function isDefault(?string $code = null): bool
    {
        return ($code ?? static::current()) === static::default();
    }

    /**
     * Every language content can be in, the default first.
     *
     * @return array<string, string> code => name
     */
    public static function all(): array
    {
        $languages = Locale::options();
        $site = CurrentSite::get();

        // A site may use some of the CMS's languages; its default always, and first.
        if (filled($site?->locales)) {
            $languages = array_intersect_key($languages, array_flip([...$site->locales, static::default()]));
        }

        return [static::default() => $languages[static::default()] ?? static::default()] + $languages;
    }

    /**
     * The other languages a page can be translated into.
     *
     * @return array<string, string> code => name
     */
    public static function translations(): array
    {
        return array_diff_key(static::all(), [static::default() => true]);
    }

    /**
     * The start of the website's addresses in a language: nothing for the default, /en for English.
     */
    public static function prefix(?string $code = null): string
    {
        $code ??= static::current();

        return static::isDefault($code) ? '' : '/'.Locale::prefixFor($code);
    }
}
