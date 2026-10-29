<?php

namespace Workbench\App\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Workbench\App\Filament\Resources\AssetTags\AssetTagResource;

/**
 * A package's Filament plugin, as a website adds one with AdminPanel::plugins(): a resource
 * for a model that doesn't belong to a site.
 */
class TagsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'workbench-tags';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([AssetTagResource::class]);
    }

    public function boot(Panel $panel): void {}
}
