<?php

namespace Aurora\Console\Commands;

use Aurora\Models\Page;
use Aurora\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Every page's entry in the search index made again from its content (what Settings › Search
 * index › Reindex all does for one site): after importing content, changing a page outside
 * the admin, or moving to another search service. Pages visitors can't find (drafts, hidden
 * from search) are taken out of it.
 */
#[Signature('aurora:reindex
    {--site= : Only this site\'s pages, by its handle}')]
#[Description('Rebuild the search index from every page\'s content')]
class Reindex extends Command
{
    public function handle(): int
    {
        $pages = Page::acrossSites();

        if (filled($handle = $this->option('site'))) {
            $site = Site::query()->where('handle', $handle)->first();

            if ($site === null) {
                $this->components->error("There is no site [{$handle}]. Use one of: ".Site::query()->pluck('handle')->implode(', ').'.');

                return self::FAILURE;
            }

            $pages->where('site_id', $site->getKey());
        }

        $total = (clone $pages)->count();
        $searchable = 0;

        $this->withProgressBar($pages->lazyById(), function (Page $page) use (&$searchable): void {
            $page->reindex();
            $searchable += $page->shouldBeSearchable() ? 1 : 0;
        });

        $this->newLine(2);
        $this->components->info($total.' '.Str::plural('page', $total)." reindexed, {$searchable} of them searchable.");

        return self::SUCCESS;
    }
}
