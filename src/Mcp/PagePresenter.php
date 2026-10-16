<?php

namespace Aurora\Mcp;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\BlockOutline;
use Aurora\Support\BlockTreeBuilder;

/**
 * How pages look to an AI assistant: the facts it needs, links a person can click.
 */
class PagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Page $page, ?User $user = null): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'path' => $page->path(),
            'parent_id' => $page->parent_id,
            'status' => $page->isScheduled() ? 'scheduled' : $page->status->value,
            'is_homepage' => $page->is_homepage,
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
            'author' => $page->author?->name,
            'blocks' => $page->blockCount(),
            'can_edit' => $user?->can('update', $page) ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(Page $page, ?User $user = null): array
    {
        return [
            ...static::summary($page, $user),
            'excerpt' => $page->excerpt,
            'layout' => $page->layout->value,
            'seo' => [
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'og_image' => $page->og_image,
                'noindex' => $page->noindex,
                'exclude_from_search' => $page->exclude_from_search,
                'password_protected' => $page->isPasswordProtected(),
            ],
            'links' => static::links($page),
            'outline' => static::outlineText(BlockOutline::for($page->blocks ?? [])),
            'blocks' => BlockTreeBuilder::nest($page->blocks ?? []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function links(Page $page): array
    {
        return [
            'live' => $page->url(),
            'preview' => $page->previewUrl(120),
            'visual_editor' => PageResource::editorUrl($page),
            'settings' => PageResource::settingsUrl($page),
        ];
    }

    /**
     * A compact, indented outline: the fastest way for a model to see a page's shape.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function outlineText(array $nodes, int $depth = 0): string
    {
        $lines = [];

        foreach ($nodes as $node) {
            $lines[] = str_repeat('  ', $depth).'- '.$node['type'].' ['.$node['id'].']'
                .($node['slot'] && $depth > 0 ? ' in '.$node['slot'] : '')
                .($node['summary'] ? ': '.$node['summary'] : '');

            if ($node['children'] !== []) {
                $lines[] = static::outlineText($node['children'], $depth + 1);
            }
        }

        return implode("\n", array_filter($lines));
    }
}
