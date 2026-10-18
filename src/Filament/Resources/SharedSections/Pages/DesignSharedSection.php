<?php

namespace Aurora\Filament\Resources\SharedSections\Pages;

use Aurora\Filament\Resources\SharedSections\SharedSectionResource;
use Aurora\Support\EditLocks;
use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use Filament\Notifications\Notification;

/**
 * A shared section on the canvas: built like a page, shown wherever it's placed.
 */
class DesignSharedSection extends BaseDesignPage
{
    protected static string $resource = SharedSectionResource::class;

    public function exitLabel(): string
    {
        return __('Exit');
    }

    public function exitUrl(): ?string
    {
        return SharedSectionResource::getUrl('index');
    }

    public function save(): void
    {
        // Someone else is editing it: say so, instead of saving over their work.
        if (EditLocks::heldByOther($this->getRecord())) {
            Notification::make()
                ->title(__(':name is editing this section', ['name' => EditLocks::holder($this->getRecord())['name']]))
                ->body(__('Your changes weren’t saved. Take over editing to save them.'))
                ->danger()
                ->send();

            return;
        }

        parent::save();
    }
}
