<?php

namespace Aurora\Console\Commands;

use Aurora\Models\Site;
use Aurora\Support\CurrentSite;
use Aurora\Support\WordPressImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aurora:import-wordpress {file : The WordPress export (.xml, from Tools › Export)} {--site= : The site to import into (its handle); the default site if left out} {--without-posts : Bring the pages only, not the blog posts} {--without-media : Leave the images where they are}')]
#[Description('Bring a WordPress site in from its export file: its pages, posts, images and menus')]
class ImportWordPress extends Command
{
    public function handle(): int
    {
        $site = filled($handle = $this->option('site')) ? Site::query()->where('handle', $handle)->first() : Site::default();

        if ($site === null) {
            $this->components->error("There's no site \"{$handle}\".");

            return self::FAILURE;
        }

        $result = CurrentSite::using($site, fn (): array => (new WordPressImport(withPosts: ! $this->option('without-posts'), withMedia: ! $this->option('without-media')))->import((string) $this->argument('file')));

        $this->components->info("Imported {$result['pages']} pages, {$result['posts']} posts, {$result['images']} images and {$result['menus']} menus into {$site->name}.");

        foreach ($result['skipped'] as $skipped) {
            $this->components->warn("Skipped {$skipped}");
        }

        return self::SUCCESS;
    }
}
