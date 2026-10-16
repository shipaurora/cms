<?php

namespace Aurora\Filament\Resources\McpActivities\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\McpActivities\McpActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListMcpActivities extends ListRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = McpActivityResource::class;

    public function getSubheading(): ?string
    {
        return __('Every action an AI assistant took over MCP, and the person it acted for.');
    }
}
