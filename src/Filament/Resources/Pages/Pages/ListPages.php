<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Concerns\ManagesLayouts;
use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Pages\Actions\ReindexAllAction;
use Aurora\Filament\Resources\Pages\Actions\SearchPagesAction;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPages extends ListRecords
{
    use ManagesLayouts, WithoutBreadcrumbs;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The title first, then the page: it opens with the title (and its address) filled in.
            // Also opened from elsewhere with ?action=create.
            Action::make('create')
                ->label(__('New page'))
                ->icon('phosphor-plus')
                ->authorize(fn (): bool => PageResource::canCreate())
                ->modalHeading(__('New page'))
                ->modalIcon('phosphor-file-plus')
                ->modalWidth('md')
                ->modalSubmitActionLabel(__('Continue'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->placeholder(__('e.g. About us'))
                        ->required()
                        ->maxLength(255)
                        ->autofocus(),
                ])
                ->action(fn (array $data) => $this->redirect(PageResource::getUrl('create', ['title' => $data['title']]), navigate: true)),
            ActionGroup::make([
                // The layouts pages are drawn with (those who manage settings).
                $this->layoutsAction(),
                // Try a search across the site's pages (only those who manage search).
                SearchPagesAction::make(),
                // Every page of the site in search again: after a change of search driver, say.
                ReindexAllAction::make()->color(null),
            ])
                ->label(__('More'))
                ->icon('phosphor-dots-three-vertical')
                ->button()
                ->color('gray'),
        ];
    }

    public function getTabs(): array
    {
        $query = fn () => PageResource::getEloquentQuery();

        return [
            'all' => Tab::make(__('All pages'))
                ->icon('phosphor-squares-four'),
            // As the Status column shows them: the same names, icons and colours.
            'live' => Tab::make(PageStatus::Published->getLabel())
                ->icon(PageStatus::Published->getIcon())
                ->badge($query()->live()->count() ?: null)
                ->badgeColor(PageStatus::Published->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->live()),
            'scheduled' => Tab::make(__('Scheduled'))
                ->icon('phosphor-clock')
                ->badge($query()->where('status', PageStatus::Published)->where('published_at', '>', now())->count() ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PageStatus::Published)->where('published_at', '>', now())),
            // Came down by themselves (their unpublish date passed): only once there are some.
            'ended' => Tab::make(__('Ended'))
                ->icon('phosphor-calendar-x')
                ->badge($ended = $query()->ended()->count() ?: null)
                ->badgeColor('gray')
                ->visible($ended !== null)
                ->modifyQueryUsing(fn (Builder $query) => $query->ended()),
            'review' => Tab::make(PageStatus::Review->getLabel())
                ->icon(PageStatus::Review->getIcon())
                ->badge($query()->where('status', PageStatus::Review)->count() ?: null)
                ->badgeColor(PageStatus::Review->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PageStatus::Review)),
            'drafts' => Tab::make(PageStatus::Draft->getLabel())
                ->icon(PageStatus::Draft->getIcon())
                ->badge($query()->where('status', PageStatus::Draft)->count() ?: null)
                ->badgeColor(PageStatus::Draft->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PageStatus::Draft)),
        ];
    }
}
