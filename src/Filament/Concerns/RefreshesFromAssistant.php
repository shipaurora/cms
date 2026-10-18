<?php

namespace Aurora\Filament\Concerns;

use Livewire\Attributes\On;

/**
 * The assistant writes straight to the database. Redraw this form from that record
 * instead of reloading the admin page, so the chat and the rest of the screen stay
 * where they are. Unsaved edits are left alone; the chat asks before those are replaced.
 */
trait RefreshesFromAssistant
{
    #[On('cms-copilot-refresh')]
    public function refreshFromAssistant(): void
    {
        if ($this->hasUnsavedChanges()) {
            return;
        }

        $this->getRecord()->refresh();
        $this->fillForm();
        $this->rememberData();
    }

    /**
     * Whether the form holds unsaved edits: ConfirmsLeaving's check, the same one Filament's
     * leave-the-page warning makes.
     */
    abstract public function hasUnsavedChanges(): bool;
}
