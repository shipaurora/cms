<?php

namespace Aurora\Filament\Resources\Roles\Pages;

use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditRole extends EditRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use FollowsActiveLocale;
    use SavesFromHeader;
    use Translatable {
        Translatable::updatedActiveLocale as updatedTranslatableActiveLocale;
        FollowsActiveLocale::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = RoleResource::class;

    /**
     * @var array<int, string>|null
     */
    protected ?array $permissions = null;

    /**
     * The permissions, once, for the form on screen: the language switcher keeps a copy of each
     * language's data and lays it over the form when switching, which would undo ticked boxes.
     */
    protected function afterFill(): void
    {
        $this->form->fill([...$this->form->getRawState(), ...RoleResource::permissionState($this->record)]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Called again for each other language with the form as it was in that language:
        // the permissions are the ones on screen now, from the first call.
        $this->permissions ??= RoleResource::permissionsFrom($data, $this->record);

        return Arr::only($data, ['label', 'description']);
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions($this->permissions ?? []);
    }

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count(RoleResource::getTranslatableLocales()) > 1),
            DeleteAction::make(),
            $this->headerSaveButton(),
        ];
    }
}
