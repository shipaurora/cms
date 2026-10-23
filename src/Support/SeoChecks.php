<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Aurora\Models\Setting;

/**
 * What would help a page in search results, worked out from the page itself: its title and
 * description, its headings (one H1, no levels skipped), how much it says, its links (none
 * leading nowhere or through a redirect, none without text), its images' alt text, its
 * share image, and titles or descriptions it shares with another page. Headings, words and
 * links are read from the page as visitors get it, drawn with its layout (RenderedPage).
 * On the page's Search engines tab, in Settings › Search & SEO › SEO report, and for AI
 * apps (seo_report).
 */
class SeoChecks
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MIN = 70;

    public const DESCRIPTION_MAX = 160;

    public const TITLE_MIN = 30;

    /**
     * Fewer words than this is thin for search engines (for a page meant to be found).
     */
    public const WORDS_MIN = 300;

    /**
     * How many of the links that lead nowhere a message names.
     */
    protected const LINKS_NAMED = 3;

    /**
     * The issues on a page, most important first.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public static function for(Page $page): array
    {
        $issues = [];
        $title = $page->seoTitle();
        $description = (string) ($page->meta_description ?: $page->excerpt);
        $blocks = $page->localizedBlocks();

        $rendered = RenderedPage::summary($page);

        if (mb_strlen($title) > self::TITLE_MAX) {
            $issues[] = ['level' => 'warning', 'message' => __('The title is :count characters: search results cut it off after about :max.', ['count' => mb_strlen($title), 'max' => self::TITLE_MAX])];
        } elseif (mb_strlen($title) < self::TITLE_MIN) {
            $issues[] = ['level' => 'info', 'message' => __('The title is short: :count characters. Around :min to :max tells searchers more.', ['count' => mb_strlen($title), 'min' => self::TITLE_MIN, 'max' => self::TITLE_MAX])];
        }

        if ($description === '') {
            $issues[] = ['level' => 'danger', 'message' => __('No meta description or summary: search engines make up their own text.')];
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $issues[] = ['level' => 'warning', 'message' => __('The description is :count characters: search results cut it off after about :max.', ['count' => mb_strlen($description), 'max' => self::DESCRIPTION_MAX])];
        } elseif (mb_strlen($description) < self::DESCRIPTION_MIN) {
            $issues[] = ['level' => 'info', 'message' => __('The description is short: :count characters. Around :min to :max says more.', ['count' => mb_strlen($description), 'min' => self::DESCRIPTION_MIN, 'max' => self::DESCRIPTION_MAX])];
        }

        // From the page as drawn, H1s from any block or the layout; else from its blocks.
        $mainHeadings = $rendered['h1'] ?? count(array_filter($blocks, fn (array $block): bool => ($block['type'] ?? null) === 'hero'
            || (($block['type'] ?? null) === 'heading' && ($block['data']['level'] ?? null) === 'h1')));

        if ($mainHeadings === 0) {
            $issues[] = ['level' => 'warning', 'message' => __('No main heading (H1, or a hero): search engines read it as what the page is about.')];
        } elseif ($mainHeadings > 1) {
            $issues[] = ['level' => 'warning', 'message' => __(':count H1 headings: a page should have one, saying what it is about. Make the others H2.', ['count' => $mainHeadings])];
        }

        if ($rendered !== null) {
            array_push($issues, ...static::renderedIssues($rendered, $page));
        }

        $withoutAlt = count(array_filter($blocks, fn (array $block): bool => ($block['type'] ?? null) === 'image'
            && filled($block['data']['src'] ?? null)
            && blank($block['data']['alt'] ?? null)
            && blank(AssetLibrary::alt($block['data']['src']))));

        if ($withoutAlt > 0) {
            $issues[] = ['level' => 'warning', 'message' => trans_choice(':count image has no alt text: search engines and screen readers can’t tell what it shows.|:count images have no alt text: search engines and screen readers can’t tell what they show.', $withoutAlt)];
        }

        if (blank($page->og_image) && blank(Setting::get('og_image'))) {
            $issues[] = ['level' => 'info', 'message' => __('No share image: links to the page on social media show none.')];
        }

        $others = Page::query()->whereKeyNot($page->getKey())->live();

        if ((clone $others)->get(['id', 'title', 'meta_title'])->contains(fn (Page $other): bool => mb_strtolower($other->seoTitle()) === mb_strtolower($title))) {
            $issues[] = ['level' => 'warning', 'message' => __('Another page has the same title: search engines can’t tell them apart.')];
        }

        if ($description !== '' && (clone $others)->get(['id', 'meta_description', 'excerpt'])->contains(fn (Page $other): bool => (string) ($other->meta_description ?: $other->excerpt) === $description)) {
            $issues[] = ['level' => 'info', 'message' => __('Another page has the same description.')];
        }

        if ($page->noindex) {
            $issues[] = ['level' => 'info', 'message' => __('Hidden from search engines (noindex), on purpose.')];
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        $issues = array_values($issues);
        usort($issues, fn (array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

        return $issues;
    }

    /**
     * What the page as drawn says about its headings, length, links and images.
     *
     * @param  array{h1: int, headings: array<int, int>, words: int, empty_links: int, links: array<int, string>, images_without_alt: int}  $rendered
     * @return array<int, array{level: string, message: string}>
     */
    protected static function renderedIssues(array $rendered, Page $page): array
    {
        $issues = [];

        // H2 straight to H4: the outline search engines and screen readers follow has a gap.
        $previous = null;

        foreach ($rendered['headings'] as $level) {
            if ($previous !== null && $level > $previous + 1) {
                $issues[] = ['level' => 'info', 'message' => __('A heading skips a level (H:from to H:to): search engines and screen readers follow headings as an outline.', ['from' => $previous, 'to' => $level])];

                break;
            }

            $previous = $level;
        }

        if (! $page->noindex && $rendered['words'] < self::WORDS_MIN) {
            $issues[] = ['level' => 'info', 'message' => __('The page says little: :count words. Pages meant to be found in search usually say :min or more.', ['count' => $rendered['words'], 'min' => self::WORDS_MIN])];
        }

        $statuses = collect($rendered['links'])->mapWithKeys(fn (string $href): array => [$href => InternalLinks::status($href)]);
        $broken = $statuses->filter(fn (string $status): bool => $status === InternalLinks::BROKEN)->keys();
        $redirected = $statuses->filter(fn (string $status): bool => $status === InternalLinks::REDIRECT)->keys();

        if ($broken->isNotEmpty()) {
            $issues[] = ['level' => 'warning', 'message' => trans_choice(':count link leads nowhere (a page that isn’t live, or was removed): :links|:count links lead nowhere (pages that aren’t live, or were removed): :links', $broken->count(), ['links' => $broken->take(self::LINKS_NAMED)->implode(', ').($broken->count() > self::LINKS_NAMED ? ' …' : '')])];
        }

        if ($redirected->isNotEmpty()) {
            $issues[] = ['level' => 'info', 'message' => trans_choice(':count link goes through a redirect: link to the page’s address itself. :links|:count links go through a redirect: link to the pages’ addresses themselves. :links', $redirected->count(), ['links' => $redirected->take(self::LINKS_NAMED)->implode(', ').($redirected->count() > self::LINKS_NAMED ? ' …' : '')])];
        }

        if ($rendered['empty_links'] > 0) {
            $issues[] = ['level' => 'warning', 'message' => trans_choice(':count link has no text: search engines and screen readers can’t tell where it goes.|:count links have no text: search engines and screen readers can’t tell where they go.', $rendered['empty_links'])];
        }

        if ($rendered['images_without_alt'] > 0) {
            $issues[] = ['level' => 'warning', 'message' => trans_choice(':count image has no alt attribute at all: give it alt text, or alt="" if it’s only decoration.|:count images have no alt attribute at all: give them alt text, or alt="" if they’re only decoration.', $rendered['images_without_alt'])];
        }

        return $issues;
    }

    /**
     * The site's live pages with what would help each, the ones needing most first.
     *
     * @return array<int, array{page: Page, issues: array<int, array{level: string, message: string}>}>
     */
    public static function report(): array
    {
        return Page::query()->live()->orderByTitle()->get()
            ->map(fn (Page $page): array => ['page' => $page, 'issues' => array_values(array_filter(static::for($page), fn (array $issue): bool => $issue['level'] !== 'info'))])
            ->filter(fn (array $row): bool => $row['issues'] !== [])
            ->sortByDesc(fn (array $row): int => count($row['issues']))
            ->values()
            ->all();
    }
}
