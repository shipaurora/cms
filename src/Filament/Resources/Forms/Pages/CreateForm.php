<?php

namespace Aurora\Filament\Resources\Forms\Pages;

use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Forms\FormResource;
use Filament\Resources\Pages\CreateRecord;

class CreateForm extends CreateRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use SavesFromHeader;

    /**
     * A new form opens for building straight away, so there is no "create another".
     */
    protected static bool $canCreateAnother = false;

    protected static string $resource = FormResource::class;

    /**
     * The language being edited, which the fields section is shown for (FormResource::inLanguage()).
     * A new form has no language switcher, so it stays empty: its fields are in the website's
     * language. It has to exist all the same: Livewire's $wire answers a property it doesn't
     * have with a function, not undefined, so without it the fields section never showed.
     */
    public ?string $activeLocale = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->headerSaveButton(),
        ];
    }
}
