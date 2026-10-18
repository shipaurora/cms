<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Aurora\Models\Setting;

/**
 * What would help a page in search results, worked out from the page itself: its title and
 * description, its main heading, its images' alt text, its share image, and titles or
 * descriptions it shares with another page. On the page's Search engines tab, in
 * Settings › Search & SEO › SEO report, and for AI apps (seo_report).
 */
class SeoChecks
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MIN = 70;

    public const DESCRIPTION_MAX = 160;

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

        if (mb_strlen($title) > self::TITLE_MAX) {
            $issues[] = ['level' => 'warning', 'message' => __('The title is :count characters: search results cut it off after about :max.', ['count' => mb_strlen($title), 'max' => self::TITLE_MAX])];
        }

        if ($description === '') {
            $issues[] = ['level' => 'danger', 'message' => __('No meta description or summary: search engines make up their own text.')];
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $issues[] = ['level' => 'warning', 'message' => __('The description is :count characters: search results cut it off after about :max.', ['count' => mb_strlen($description), 'max' => self::DESCRIPTION_MAX])];
        } elseif (mb_strlen($description) < self::DESCRIPTION_MIN) {
            $issues[] = ['level' => 'info', 'message' => __('The description is short: :count characters. Around :min to :max says more.', ['count' => mb_strlen($description), 'min' => self::DESCRIPTION_MIN, 'max' => self::DESCRIPTION_MAX])];
        }

        $mainHeadings = count(array_filter($blocks, fn (array $block): bool => ($block['type'] ?? null) === 'hero'
            || (($block['type'] ?? null) === 'heading' && ($block['data']['level'] ?? null) === 'h1')));

        if ($mainHeadings === 0) {
            $issues[] = ['level' => 'warning', 'message' => __('No main heading (H1, or a hero): search engines read it as what the page is about.')];
        } elseif ($mainHeadings > 1) {
            $issues[] = ['level' => 'info', 'message' => __(':count main headings: one says best what the page is about.', ['count' => $mainHeadings])];
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
        usort($issues, fn (array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

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
