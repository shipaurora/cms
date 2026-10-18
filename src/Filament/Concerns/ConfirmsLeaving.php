<?php

namespace Aurora\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;

/**
 * Leaving a form with unsaved changes asks first, in Filament's dialog rather than the
 * browser's: save and leave, or discard the changes. The admin's script (form-leave.js)
 * catches links clicked while the form has changes and opens it; reloading, closing the tab
 * or going back still get the browser's own prompt, the only one browsers allow there.
 */
trait ConfirmsLeaving
{
    /**
     * Tells the admin's script this page asks before it's left.
     */
    public bool $confirmsLeaving = true;

    /**
     * Whether the form holds changes not saved yet: what Filament's unsaved-changes warning
     * compares, on the server.
     */
    public function hasUnsavedChanges(): bool
    {
        return isset($this->savedDataHash)
            && md5((string) str(json_encode($this->data, JSON_UNESCAPED_UNICODE))->replace('\\', '')) !== $this->savedDataHash;
    }

    public function leavePageAction(): Action
    {
        return Action::make('leavePage')
            ->modalIcon('phosphor-floppy-disk')
            ->modalHeading(__('Save your changes first?'))
            ->modalDescription(__('Your changes aren’t saved yet. Save them before you go, or leave without them.'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(fn (): string => $this instanceof CreateRecord ? __('Create and leave') : __('Save and leave'))
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('discardAndLeave')
                    ->label(__('Discard changes'))
                    ->color('gray')
                    ->cancelParentActions()
                    ->action(fn () => $this->redirect(static::leavingTo($arguments['url'] ?? null), navigate: true)),
            ])
            ->action(function (array $arguments): void {
                match (true) {
                    $this instanceof CreateRecord => $this->create(),
                    $this instanceof EditRecord => $this->save(shouldRedirect: false),
                    default => $this->save(),
                };

                // Not saved (a field to fix, or a question to answer first): stay.
                if ($this->hasUnsavedChanges()) {
                    return;
                }

                $this->redirect(static::leavingTo($arguments['url'] ?? null), navigate: true);
            });
    }

    /**
     * Where the link led, on this site only; anywhere else, the dashboard.
     */
    protected static function leavingTo(mixed $url): string
    {
        $url = is_string($url) ? $url : '';

        return str_starts_with($url, url('/').'/') || $url === url('/') || (str_starts_with($url, '/') && ! str_starts_with($url, '//'))
            ? $url
            : filament()->getUrl();
    }
}
