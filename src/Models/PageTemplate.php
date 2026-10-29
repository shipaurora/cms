<?php

namespace Aurora\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * A page template: a page that's never on the website, which new pages start from (its
 * layout, layout fields, blocks and their translations; Aurora\Support\PageTemplates). Kept in
 * the pages table and edited like a page, in the visual editor, but always only the templates:
 * Page never returns one, and this never returns a page.
 */
class PageTemplate extends Page
{
    protected $table = 'pages';

    protected static function booted(): void
    {
        static::addGlobalScope('template', function (Builder $query): void {
            $query->where($query->qualifyColumn('is_template'), true);
        });

        static::creating(function (PageTemplate $template): void {
            $template->is_template = true;
        });
    }

    /**
     * Its revisions and the rest are a page's: keyed by page_id.
     */
    public function getForeignKey(): string
    {
        return 'page_id';
    }
}
