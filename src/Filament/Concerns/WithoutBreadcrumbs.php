<?php

namespace Aurora\Filament\Concerns;

/**
 * For top-level screens (the lists in the sidebar): the page title already says where
 * you are, so there is no breadcrumb trail above it.
 */
trait WithoutBreadcrumbs
{
    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
