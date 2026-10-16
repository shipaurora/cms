<?php

namespace Aurora\Filament\Concerns;

/**
 * For create and edit screens: the breadcrumb is just the way back to the list. The record's
 * title (and "Create"/"Edit") is already the heading right below it.
 */
trait BreadcrumbsToList
{
    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();

        return [$resource::getUrl('index') => $resource::getBreadcrumb()];
    }
}
