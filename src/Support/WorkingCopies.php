<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Closure;

/**
 * Changes to a live page that aren't on the website yet. While people (the page form, the
 * canvas) or AI tools save a live page, what it says goes into its working copy instead of
 * onto the site, until someone publishes the changes. Its address and publishing settings
 * still apply straight away.
 */
class WorkingCopies
{
    /**
     * What a working copy holds: what the page says and how it looks (Page::SNAPSHOT, less
     * the address and place in the tree, which have redirects of their own).
     *
     * @var array<int, string>
     */
    public const KEYS = ['title', 'excerpt', 'blocks', 'block_translations', 'locales', 'layout_id', 'meta_title', 'meta_description', 'og_image', 'noindex', 'exclude_from_search'];

    protected static int $capturing = 0;

    /**
     * Run a save in which changes to live pages go into their working copies.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function capture(Closure $callback): mixed
    {
        static::$capturing++;

        try {
            return $callback();
        } finally {
            static::$capturing--;
        }
    }

    public static function isCapturing(): bool
    {
        return static::$capturing > 0;
    }

    /**
     * Run without capturing, inside a capture (publishing the changes).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        $was = static::$capturing;
        static::$capturing = 0;

        try {
            return $callback();
        } finally {
            static::$capturing = $was;
        }
    }

    /**
     * Before a page is saved (PageObserver): a live page's changes to what it says move into
     * its working copy, and the page keeps saying what it said. A page that isn't live (or
     * is coming down in this save) takes them directly, and its working copy is spent.
     */
    public static function intercept(Page $page): void
    {
        if (! static::isCapturing() || ! $page->exists) {
            return;
        }

        if (! static::stayedLive($page)) {
            if ($page->working_copy !== null) {
                $page->forceFill(['working_copy' => null, 'working_copy_by_id' => null, 'working_copy_at' => null]);
            }

            return;
        }

        $changed = array_values(array_filter(static::KEYS, fn (string $key): bool => $page->isDirty($key)));

        if ($changed === []) {
            return;
        }

        $snapshot = $page->toSnapshot();
        $working = [...($page->working_copy ?? []), ...array_intersect_key($snapshot, array_flip($changed))];

        // The page goes back to what it said; the changes wait in the working copy.
        $page->setRawAttributes([...$page->getAttributes(), ...array_intersect_key($page->getRawOriginal(), array_flip($changed))]);
        $page->forceFill([
            'working_copy' => $working,
            'working_copy_by_id' => auth()->id(),
            'working_copy_at' => now(),
        ]);
    }

    /**
     * Live before this save and still live after it.
     */
    protected static function stayedLive(Page $page): bool
    {
        if (! $page->isLive()) {
            return false;
        }

        $before = (clone $page)->setRawAttributes($page->getRawOriginal());

        return $before->isLive();
    }
}
