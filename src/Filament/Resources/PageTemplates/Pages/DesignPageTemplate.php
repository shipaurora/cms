<?php

namespace Aurora\Filament\Resources\PageTemplates\Pages;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\PageTemplates\PageTemplateResource;

/**
 * A page template on the canvas: designed like a page, with its layout and blocks. "Exit"
 * goes back to the templates (Settings › Templates), for those who may open Settings, else to
 * the page list, where its "Page templates" dialog is.
 */
class DesignPageTemplate extends DesignPage
{
    protected static string $resource = PageTemplateResource::class;

    public function exitUrl(): ?string
    {
        return SiteSettings::canAccess()
            ? SiteSettings::getUrl(['tab' => 'templates', 'section' => 'page-templates'])
            : PageResource::getUrl('index');
    }
}
