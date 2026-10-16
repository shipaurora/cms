<?php

namespace Aurora\Filament\Resources\Roles\Pages;

use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use SavesFromHeader;

    /**
     * Roles are set up one at a time, so there is no "create another".
     */
    protected static bool $canCreateAnother = false;

    protected static string $resource = RoleResource::class;

    /**
     * @var array<int, string>
     */
    protected array $permissions = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissions = RoleResource::permissionsFrom($data);

        // Named in the admin's language for now; other languages are added when editing.
        return [
            'name' => $data['label'],
            'label' => [app()->getLocale() => $data['label']],
            'description' => filled($data['description'] ?? null) ? [app()->getLocale() => $data['description']] : null,
            'guard_name' => 'web',
        ];
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions($this->permissions);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->headerSaveButton(),
        ];
    }
}
