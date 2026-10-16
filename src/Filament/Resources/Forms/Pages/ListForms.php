<?php

namespace Aurora\Filament\Resources\Forms\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Forms\FormResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListForms extends ListRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = FormResource::class;

    public function getSubheading(): ?string
    {
        return __('Forms you can place on any page with the Form block. Everything people send ends up here.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New form'))->icon('phosphor-plus'),
        ];
    }
}
