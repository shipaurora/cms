<?php

namespace Aurora\Filament\Resources\Pages\Actions;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Filament\Actions\Action;

/**
 * "Open visual editor", in the page's header and its Content section: the canvas, in the
 * language being edited (another language opens it to translate). The canvas works on the
 * saved page, so with unsaved changes in the form it asks first: save them and open, or
 * discard them. Also what the Content section's preview and blank canvas open.
 */
class OpenVisualEditorAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'design';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Open visual editor'))
            ->icon('phosphor-paint-brush')
            ->color('gray')
            ->visible(fn (EditPage $livewire): bool => PageResource::canEdit($livewire->getRecord()))
            ->keyBindings(['mod+e'])
            // Nothing to save: straight to the canvas.
            ->modalHidden(fn (EditPage $livewire): bool => ! $livewire->hasUnsavedChanges())
            ->modalIcon('phosphor-floppy-disk')
            ->modalHeading(__('Save your changes first?'))
            ->modalDescription(__('The visual editor opens the page as it was last saved. Save your changes now, or open it without them.'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('Save and open'))
            ->extraModalFooterActions(fn (): array => [
                Action::make('discardChanges')
                    ->label(__('Discard changes'))
                    ->color('gray')
                    ->cancelParentActions()
                    ->action(fn (EditPage $livewire) => $livewire->redirect(static::editorUrl($livewire->getRecord()), navigate: true)),
            ])
            ->action(function (EditPage $livewire): void {
                if ($livewire->hasUnsavedChanges()) {
                    $livewire->save(shouldRedirect: false);

                    // Not saved (a field to fix, or the old addresses to decide on first): stay.
                    if ($livewire->hasUnsavedChanges()) {
                        return;
                    }
                }

                $livewire->redirect(static::editorUrl($livewire->getRecord()), navigate: true);
            });
    }

    public static function editorUrl(Page $page): string
    {
        $locale = ContentLocale::current();

        return PageResource::getUrl('design', ['record' => $page, ...($locale === $page->primaryLocale() ? [] : ['language' => $locale])]);
    }
}
