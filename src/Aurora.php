<?php

namespace Aurora;

/**
 * Where Aurora's own files are (its views, translations, demo content …), wherever the
 * package is installed.
 */
class Aurora
{
    /**
     * Where Aurora's documentation is.
     */
    public const DOCS_URL = 'https://shipaurora.com/docs';

    /**
     * A page of the documentation, for links from the admin, by its path under /docs
     * ("building-a-site/creating-blocks").
     */
    public static function docs(string $page = ''): string
    {
        return $page === '' ? self::DOCS_URL : self::DOCS_URL.'/'.ltrim($page, '/');
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
