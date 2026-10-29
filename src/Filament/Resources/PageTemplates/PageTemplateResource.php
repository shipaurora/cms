<?php

namespace Aurora\Filament\Resources\PageTemplates;

use Aurora\Filament\Resources\PageTemplates\Pages\DesignPageTemplate;
use Aurora\Models\PageTemplate;
use Filament\Resources\Resource;

/**
 * Page templates in the visual editor. They're listed and managed under Settings › Templates
 * and in the pages list (Aurora\Filament\Concerns\ManagesPageTemplates); this resource only
 * gives each its canvas, so it has no list or menu item of its own.
 */
class PageTemplateResource extends Resource
{
    protected static ?string $model = PageTemplate::class;

    protected static ?string $slug = 'page-templates';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('page template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Page templates');
    }

    public static function getPages(): array
    {
        return [
            'design' => DesignPageTemplate::route('/{record}/design'),
        ];
    }
}
