<?php

namespace Aurora\Filament\Concerns;

/**
 * For create and edit screens: the breadcrumb is just the way back to the list, after the
 * section it's in when there is one ("Users & Roles › Roles"). The record's title (and
 * "Create"/"Edit") is already the heading right below it. None for people who can't open the
 * list (their own profile, say).
 */
trait BreadcrumbsToList
{
    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();

        if (! $resource::canViewAny()) {
            return [];
        }

        $cluster = $resource::getCluster();

        // The section as plain text: its address opens its first list, which may be this one.
        return [
            ...(filled($cluster) ? [$cluster::getClusterBreadcrumb()] : []),
            $resource::getUrl('index') => $resource::getBreadcrumb(),
        ];
    }
}
