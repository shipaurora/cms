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
        if ($this->formHasUnsavedEdits()) {
            return;
        }

        $this->getRecord()->refresh();
        $this->fillForm();
        $this->rememberData();
    }

    /**
     * The same check Filament uses for its leave-the-page warning.
     */
    protected function formHasUnsavedEdits(): bool
    {
        if (! isset($this->savedDataHash)) {
            return false;
        }

        $hash = md5((string) str(json_encode($this->data ?? [], JSON_UNESCAPED_UNICODE))->replace('\\', ''));

        return $hash !== $this->savedDataHash;
    }
}
