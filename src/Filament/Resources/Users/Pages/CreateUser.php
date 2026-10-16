<?php

namespace Aurora\Filament\Resources\Users\Pages;

use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use SavesFromHeader;

    /**
     * People are usually invited; adding one by hand is a one-off, so there is no "create another".
     */
    protected static bool $canCreateAnother = false;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->headerSaveButton(),
        ];
    }
}
