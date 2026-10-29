<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Aurora\Models\PageTemplate;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Support\Str;

/**
 * Page templates: pages that are never on the website, which new pages start from. A new
 * page made from one (or from another page, "Start from") gets a copy of its layout, the
 * layout's fields, its blocks and their translations, and goes its own way from there:
 * changing the template later doesn't change the pages made from it.
 */
class PageTemplates
{
    /**
     * Whether the site has page templates to start from.
     */
    public static function exist(): bool
    {
        return Page::storesTemplates() && PageTemplate::query()->exists();
    }

    /**
     * What a new page starts with from a template or another page: its layout and layout
     * fields, and its blocks with ids of their own (two pages sharing block ids would confuse the
     * canvas's history), their translations kept with them.
     *
     * @return array{layout_id: ?string, blocks: array<int, array<string, mixed>>, block_translations: array<string, array<string, mixed>>, settings?: array<string, mixed>|null}
     */
    public static function content(Page $from): array
    {
        $blocks = BlockTree::hydrate($from->blocks ?? []);
        $ids = collect($blocks)->mapWithKeys(fn (array $block): array => [$block['id'] => (string) Str::uuid()])->all();

        $translations = [];

        foreach ($from->block_translations ?? [] as $locale => $byBlock) {
            foreach (is_array($byBlock) ? $byBlock : [] as $id => $fields) {
                if (isset($ids[$id])) {
                    $translations[$locale][$ids[$id]] = $fields;
                }
            }
        }

        return [
            'layout_id' => $from->layout_id,
            ...(Page::storesSettings() ? ['settings' => $from->settings] : []),
            'blocks' => array_map(fn (array $block): array => [
                ...$block,
                'id' => $ids[$block['id']],
                'parent' => $block['parent'] !== null ? ($ids[$block['parent']] ?? null) : null,
            ], $blocks),
            'block_translations' => $translations,
        ];
    }

    /**
     * A new, empty template, drawn with a layout (the default one when none is given).
     */
    public static function create(string $name, ?string $description = null, ?string $layoutId = null): PageTemplate
    {
        return PageTemplate::query()->create([
            'title' => $name,
            'excerpt' => $description,
            'layout_id' => $layoutId,
            'blocks' => [],
        ]);
    }

    /**
     * A template made from a page, as it is now: its layout, layout fields, blocks and their
     * translations, in the languages the page is written in.
     */
    public static function fromPage(Page $page, string $name, ?string $description = null): PageTemplate
    {
        $page = (clone $page)->applyWorkingCopy();

        return PageTemplate::query()->create([
            ...static::content($page),
            'title' => $name,
            'excerpt' => $description,
            'locales' => $page->locales,
        ]);
    }

    /**
     * A copy of a template, to make a variation of it.
     */
    public static function duplicate(PageTemplate $template): PageTemplate
    {
        return PageTemplate::query()->create([
            ...static::content($template),
            'title' => __(':name (copy)', ['name' => $template->title]),
            'excerpt' => $template->excerpt,
            'locales' => $template->locales,
        ]);
    }
}
