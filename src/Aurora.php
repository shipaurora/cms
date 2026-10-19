<?php

namespace Aurora;

/**
 * Where Aurora's own files are (its views, translations, demo content …), wherever the
 * package is installed.
 */
class Aurora
{
    /**
     * Where Aurora's documentation is: a placeholder until it has an address.
     */
    public const DOCS_URL = '#';

    /**
     * A section of the documentation, for links from the admin ("blocks" for making blocks).
     */
    public static function docs(string $section = ''): string
    {
        return self::DOCS_URL === '#' || $section === '' ? self::DOCS_URL : rtrim(self::DOCS_URL, '/').'/'.$section;
    }

    public static function path(string $path = ''): string
    {
        return dirname(__DIR__).($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * The lang directory labels are written back to (aurora:sync-translations and
     * aurora:export-translations): the app's own, unless config('aurora.translations_path') says otherwise.
     */
    public static function translationsPath(string $path = ''): string
    {
        $base = config('aurora.translations_path') ?: lang_path();

        return rtrim($base, '/').($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * Every lang directory labels come from, the app's last (it has the final say).
     *
     * @return array<int, string>
     */
    public static function langPaths(): array
    {
        return array_values(array_unique([static::path('lang'), lang_path()]));
    }
}
