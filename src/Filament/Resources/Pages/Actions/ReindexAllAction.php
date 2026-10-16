<?php

namespace Aurora\Filament\Resources\Pages\Actions;

use Aurora\Models\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Reindex all": every page of the site in search again, after a change of search driver,
 * say. In the pages list's "More" menu and in Settings › Search index, for those who manage
 * search.
 */
class ReindexAllAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reindexAll';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Reindex all'))
            ->icon('phosphor-arrows-clockwise')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('manage-search Page'))
            ->requiresConfirmation()
            ->modalIcon('phosphor-arrows-clockwise')
            ->modalHeading(__('Reindex every page?'))
            ->modalDescription(__('Each page’s text is worked out again and sent to search. Worth doing after changing the search driver, or if search seems out of date.'))
            ->modalSubmitActionLabel(__('Reindex all'))
            ->action(function (): void {
                $count = 0;
                Page::query()->each(function (Page $page) use (&$count): void {
                    $page->reindex();
                    $count++;
                });

                Notification::make()->title(trans_choice(':count page reindexed for search|:count pages reindexed for search', $count))->success()->send();
            });
    }
}
