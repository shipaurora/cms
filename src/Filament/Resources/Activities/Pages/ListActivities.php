<?php

namespace Aurora\Filament\Resources\Activities\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = ActivityResource::class;

    public function getSubheading(): ?string
    {
        return __('Who changed what across the CMS, in the admin or through an AI app.');
    }
}
