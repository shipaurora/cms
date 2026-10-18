<?php

namespace Aurora\Livewire;

use Aurora\Support\EditLocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The page's edit lock, on its edit form and canvas: pings while the page is open (so the
 * lock lasts), says who else is editing when someone is, and offers to take over. Lets the
 * lock go on leaving (edit-lock.blade.php); a lock nobody pings goes by itself.
 */
class EditLock extends Component
{
    #[Locked]
    public string $model;

    #[Locked]
    public string $id;

    /**
     * Whether this person held the lock at the last ping, to tell them when it was taken.
     */
    public bool $held = false;

    public function mount(Model $record): void
    {
        $this->model = $record::class;
        $this->id = (string) $record->getKey();
        $this->ping();
    }

    public function ping(): void
    {
        $holder = EditLocks::ping($this->record(), auth()->user());
        $this->held = $holder['user_id'] === auth()->id();
    }

    public function takeOver(): void
    {
        EditLocks::take($this->record(), auth()->user());
        $this->held = true;
    }

    public function release(): void
    {
        EditLocks::release($this->record(), auth()->user());
    }

    public function render(): View
    {
        $holder = EditLocks::holder($this->record());

        return view('aurora::livewire.edit-lock', [
            'other' => $holder !== null && $holder['user_id'] !== auth()->id() ? $holder : null,
        ]);
    }

    protected function record(): Model
    {
        return $this->model::query()->findOrFail($this->id);
    }
}
