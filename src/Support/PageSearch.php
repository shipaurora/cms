<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Aurora\Support\Search\DatabaseEngine;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * What a page says, for search (laravel/scout): its titles, excerpt, meta description and
 * the text of its blocks, in each of its languages. Kept on the page (search_text) for the
 * database engine, and sent as a document, language by language, to a search service.
 */
class PageSearch
{
    /**
     * What the page keeps for search: its text and when it was indexed, or nothing when it's
     * left out of the index (or behind a password, so its words stay private).
     *
     * @return array{search_text: ?string, indexed_at: ?CarbonInterface}
     */
    public static function indexed(Page $page): array
    {
        return $page->exclude_from_search || $page->isPasswordProtected()
            ? ['search_text' => null, 'indexed_at' => null]
            : ['search_text' => static::text($page), 'indexed_at' => now()];
    }

    /**
     * Everything the page says, in every language, as one plain text.
     */
    public static function text(Page $page): string
    {
        return collect(static::locales($page))
            ->map(fn (string $locale): string => static::textIn($page, $locale))
            ->filter()
            ->unique()
            ->implode("\n");
    }

    /**
     * The page as a search service indexes it: what to filter on, and its text in each
     * language (title_nb, content_nb …), so a search can keep to one language.
     *
     * @return array<string, mixed>
     */
    public static function document(Page $page): array
    {
        $document = [
            'id' => $page->getKey(),
            'site_id' => $page->site_id,
            'locales' => static::locales($page),
            'is_homepage' => (bool) $page->is_homepage,
            'published_at' => $page->published_at?->getTimestamp(),
            'content' => static::text($page),
        ];

        foreach (static::locales($page) as $locale) {
            $document['title_'.$locale] = $page->getTranslation('title', $locale, false);
            $document['content_'.$locale] = static::textIn($page, $locale);
        }

        return $document;
    }

    /**
     * The page's text in one language.
     */
    public static function textIn(Page $page, string $locale): string
    {
        $parts = [
            $page->getTranslation('title', $locale, false),
            $page->getTranslation('excerpt', $locale, false),
            $page->getTranslation('meta_description', $locale, false),
            ...static::blockTexts($page->localizedBlocks($locale)),
        ];

        return collect($parts)
            ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
            // Rich text as words: a space where a heading, paragraph or list item ends, so they
            // don't run together ("Content" and "Add a language" stay two words).
            ->map(fn (string $text): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</(p|h[1-6]|li|div|blockquote|td|th|figcaption)>#i', ' ', str_replace('*', '', $text))), ENT_QUOTES | ENT_HTML5))))
            ->filter()
            ->implode("\n");
    }

    /**
     * The text fields of each block, nested blocks included.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, string>
     */
    protected static function blockTexts(array $blocks): array
    {
        $texts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (isset($block['type'], $block['data']) && is_array($block['data'])) {
                foreach (BlockTranslations::fieldsOf($block['type']) as $field) {
                    foreach (Arr::dot([$field => $block['data'][$field] ?? null]) as $text) {
                        // Words, not ids, addresses or anchors.
                        if (is_string($text) && preg_match('/\pL/u', $text) && ! Str::isUuid($text) && ! Str::startsWith($text, ['http', '/', '#'])) {
                            $texts[] = $text;
                        }
                    }
                }
            }

            foreach (['children', 'slots', 'blocks'] as $nested) {
                if (isset($block[$nested]) && is_array($block[$nested])) {
                    $texts = [...$texts, ...static::blockTexts(array_is_list($block[$nested]) ? $block[$nested] : array_merge(...array_values(array_filter($block[$nested], 'is_array'))))];
                }
            }
        }

        return $texts;
    }

    /**
     * How well each page matches a search, by id: MySQL's full-text relevance (higher is
     * better), or nothing on databases and search services that don't say.
     *
     * @param  array<int, string>  $ids
     * @return array<string, float>
     */
    public static function scores(array $ids, string $terms): array
    {
        $query = Page::query()->withoutGlobalScopes();

        if ($ids === [] || ! in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) || ! ((new Page)->searchableUsing() instanceof DatabaseEngine)) {
            return [];
        }

        return $query->whereKey($ids)
            ->selectRaw('id, MATCH(search_text) AGAINST (? IN BOOLEAN MODE) as score', [DatabaseEngine::booleanQuery($terms)])
            ->pluck('score', 'id')
            ->map(fn (mixed $score): float => round((float) $score, 2))
            ->all();
    }

    /**
     * The languages the page is in (all of the site's, when it doesn't say).
     *
     * @return array<int, string>
     */
    public static function locales(Page $page): array
    {
        return array_values(array_filter((array) ($page->locales ?: array_keys(ContentLocale::all()))));
    }
}
