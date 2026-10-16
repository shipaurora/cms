<?php

namespace Aurora\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

/**
 * Save (or Create) sits in the page header, last, instead of under the form, so it is in
 * reach on long forms; there is no Cancel, leaving the page is cancelling. It still
 * submits the form: same validation, the same save, ⌘S, the loading state and the
 * unsaved-changes warning, through the button's form="form" attribute.
 */
trait SavesFromHeader
{
    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    protected function headerSaveButton(): Action
    {
        $action = $this instanceof CreateRecord ? $this->getCreateFormAction() : $this->getSaveFormAction();

        return $action->formId('form');
    }
}
