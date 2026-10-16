<?php

namespace Aurora\Filament\Resources\Redirects\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Redirects\RedirectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRedirects extends ManageRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
