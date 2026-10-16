<?php

namespace Aurora\Filament\Resources\Roles\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = RoleResource::class;

    public function getSubheading(): ?string
    {
        return __('What each group of people can do. Everyone\'s AI assistant has exactly the same permissions as they do.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New role'))->icon('phosphor-plus'),
        ];
    }
}
