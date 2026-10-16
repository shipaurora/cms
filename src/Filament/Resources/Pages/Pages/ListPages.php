<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPages extends ListRecords
{
    use WithoutBreadcrumbs;

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
        ];
    }

    public function getTabs(): array
    {
        $query = fn () => PageResource::getEloquentQuery();

        return [
            'all' => Tab::make(__('All pages'))
                ->icon('phosphor-squares-four'),
            'live' => Tab::make(__('Live'))
                ->icon('phosphor-broadcast')
                ->badge($query()->live()->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->live()),
            'scheduled' => Tab::make(__('Scheduled'))
                ->icon('phosphor-clock')
                ->badge($query()->where('status', PageStatus::Published)->where('published_at', '>', now())->count() ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PageStatus::Published)->where('published_at', '>', now())),
            'review' => Tab::make(__('In review'))
                ->icon(PageStatus::Review->getIcon())
                ->badge($query()->where('status', PageStatus::Review)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PageStatus::Review)),
            'drafts' => Tab::make(__('Drafts'))
                ->icon(PageStatus::Draft->getIcon())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PageStatus::Draft)),
        ];
    }
}
