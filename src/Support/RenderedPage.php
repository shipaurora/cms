<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * A page as visitors get it, drawn with its layout, and what search engines read in it: its
 * headings, how much it says, its links and images (SeoChecks). Drawn once per version of
 * the page and language, then remembered.
 */
class RenderedPage
{
    /**
     * Bumped when what's read from a page changes, so nothing older is used.
     */
    protected const VERSION = 1;

    /**
     * The page's markup read for search engines, or null when it couldn't be drawn.
     *
     * @return array{h1: int, headings: array<int, int>, words: int, empty_links: int, links: array<int, string>, images_without_alt: int}|null
     */
    public static function summary(Page $page): ?array
    {
        $key = implode(':', ['aurora-rendered-page', self::VERSION, $page->getKey(), $page->updated_at?->getTimestamp(), ContentLocale::current(), md5((string) json_encode($page->working_copy))]);

        return Cache::remember($key, now()->addDay(), function () use ($page): ?array {
            $html = static::html($page);

            return $html === null ? null : static::read($html);
        });
    }

    /**
     * The page drawn as a visitor gets it: no preview, no editing.
     */
    public static function html(Page $page): ?string
    {
        $shared = array_intersect_key(View::getShared(), array_flip(['currentPage', 'previewing']));

        try {
            View::share('currentPage', $page);
            View::share('previewing', false);

            return view(Layouts::viewFor($page), [
                'page' => $page,
                'layout' => $page->layoutOrDefault(),
                'settings' => $page->layoutSettings(),
                'preview' => false,
                'embedded' => false,
            ])->render();
        } catch (Throwable) {
            return null;
        } finally {
            View::share(['currentPage' => $shared['currentPage'] ?? null, 'previewing' => $shared['previewing'] ?? false]);
        }
    }

    /**
     * Headings, words, links and images. Headings and words come from the page's own content
     * (<main>, when the layout has one), not its menu and footer; H1s from all of it.
     *
     * @return array{h1: int, headings: array<int, int>, words: int, empty_links: int, links: array<int, string>, images_without_alt: int}
     */
    public static function read(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $main = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);

        foreach ($xpath->query('//script|//style|//template|//noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $headings = [];

        foreach ($main === null ? [] : $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $main) as $heading) {
            $headings[] = (int) substr($heading->nodeName, 1);
        }

        $links = [];
        $emptyLinks = 0;

        foreach ($xpath->query('//a[@href]') as $link) {
            /** @var DOMElement $link */
            $href = trim($link->getAttribute('href'));

            if (static::linkText($link) === '') {
                $emptyLinks++;
            }

            if ($href !== '' && ! str_starts_with($href, '#')) {
                $links[] = $href;
            }
        }

        $text = $main === null ? '' : preg_replace('/\s+/u', ' ', $main->textContent);

        return [
            'h1' => $xpath->query('//h1')->length,
            'headings' => $headings,
            'words' => count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY)),
            'empty_links' => $emptyLinks,
            'links' => array_values(array_unique($links)),
            'images_without_alt' => $xpath->query('//img[not(@alt)]')->length,
        ];
    }

    /**
     * What a link says: its text, or what it's labelled with (an icon's aria-label, an
     * image's alt text).
     */
    protected static function linkText(DOMElement $link): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');

        if ($text !== '' || filled($link->getAttribute('aria-label')) || filled($link->getAttribute('title'))) {
            return $text ?: 'labelled';
        }

        foreach ($link->getElementsByTagName('img') as $image) {
            if (filled($image->getAttribute('alt'))) {
                return 'image';
            }
        }

        foreach ($link->getElementsByTagName('*') as $child) {
            if (filled($child->getAttribute('aria-label'))) {
                return 'labelled';
            }
        }

        return '';
    }
}
