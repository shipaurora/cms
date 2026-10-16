<?php

namespace Aurora\Filament\Resources\Forms\Pages;

use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Forms\FormResource;
use Filament\Resources\Pages\CreateRecord;

class CreateForm extends CreateRecord
{
    use BreadcrumbsToList;
    use SavesFromHeader;

    /**
     * A new form opens for building straight away, so there is no "create another".
     */
    protected static bool $canCreateAnother = false;

    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->headerSaveButton(),
        ];
    }
}
