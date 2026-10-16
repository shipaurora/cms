<?php

namespace Aurora\Support;

use Aurora\Enums\PageStatus;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Illuminate\Support\Str;

/**
 * Old addresses of a live page that keep working after its address changes: links in the
 * wild, search results and menus elsewhere still point there. Asked for when saving in the
 * admin, one language at a time; AI assistants ask for them with the update_page tool.
 */
class PageRedirects
{
    /**
     * The page's addresses that change, by language, given the new address in each language
     * (only those passed are compared). Only a published page has addresses to keep.
     *
     * @param  array<string, string>  $newSlugs  by locale
     * @return array<string, array{from: string, to: string}>
     */
    public static function changes(Page $page, array $newSlugs): array
    {
        if ($page->getOriginal('status') !== PageStatus::Published) {
            return [];
        }

        $changes = [];
        // Where it sat before this save (a subpage's address starts with its parents').
        $oldParent = Page::query()->withTrashed()->find($page->getOriginal('parent_id'));

        foreach ($newSlugs as $locale => $slug) {
            $old = json_decode((string) $page->getRawOriginal('slug'), true)[$locale] ?? null;

            if (filled($old) && filled($slug) && $old !== $slug) {
                $changes[$locale] = [
                    'from' => ContentLocale::prefix($locale).static::pathUnder($oldParent, $locale).'/'.$old,
                    'to' => ContentLocale::prefix($locale).$page->parentPath($locale).'/'.$slug,
                ];
            }
        }

        return $changes;
    }

    /**
     * The part of an address a page's subpages start with ("/docs"), without the language prefix.
     */
    protected static function pathUnder(?Page $parent, string $locale): string
    {
        if ($parent === null || $parent->is_homepage) {
            return '';
        }

        return Str::after($parent->path($locale), ContentLocale::prefix($parent->isAvailableIn($locale) ? $locale : $parent->primaryLocale()));
    }

    /**
     * Redirect each old address to the page, permanently.
     *
     * @param  array<int, string>  $paths
     */
    public static function keep(Page $page, array $paths): void
    {
        foreach ($paths as $path) {
            Redirect::query()->updateOrCreate(
                ['from_path' => Redirect::normalisePath($path)],
                ['page_id' => $page->getKey(), 'asset_id' => null, 'to_url' => null, 'status_code' => 301],
            );
        }
    }
}
