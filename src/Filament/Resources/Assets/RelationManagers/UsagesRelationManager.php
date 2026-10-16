<?php

namespace Aurora\Filament\Resources\Assets\RelationManagers;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Redirects\RedirectResource;
use Aurora\Models\AssetUsage;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Models\Site;
use Aurora\Support\AssetLibrary;
use Aurora\Support\CurrentSite;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    protected static ?string $title = 'Used on';

    protected static string|\BackedEnum|null $icon = 'phosphor-link';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(static::$title);
    }

    protected static function getModelLabel(): ?string
    {
        return __('place');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('places');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->usages()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            // Pages and redirects on every site use the library (shared by all sites).
            ->modifyQueryUsing(fn ($query) => $query->with(['usable' => fn (MorphTo $morphTo) => $morphTo->constrain([
                Page::class => fn ($query) => $query->withoutGlobalScope('site')->with('site'),
                Redirect::class => fn ($query) => $query->withoutGlobalScope('site')->with('site'),
            ])]))
            ->columns([
                TextColumn::make('usable')
                    ->label(__('Used by'))
                    ->weight(FontWeight::SemiBold)
                    ->state(fn (AssetUsage $record): ?string => match (true) {
                        $record->usable instanceof Page => $record->usable->title,
                        $record->usable instanceof Redirect => $record->usable->from_path,
                        default => null,
                    })
                    ->description(fn (AssetUsage $record): ?string => collect([
                        Site::query()->count() > 1 ? $record->usable?->site?->name : null,
                        $record->usable instanceof Page ? CurrentSite::using($record->usable->site, fn (): string => $record->usable->path()) : null,
                    ])->filter()->join(' · ') ?: null),
                TextColumn::make('location')
                    ->label(__('Where'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => AssetLibrary::describeLocation($state)),
                TextColumn::make('updated_at')->label(__('Since'))->since()->color('gray'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(fn (AssetUsage $record): string => $record->usable instanceof Redirect ? __('Open redirects') : __('Open in editor'))
                    ->icon(fn (AssetUsage $record): string => $record->usable instanceof Redirect ? 'phosphor-arrow-u-up-right' : 'phosphor-paint-brush')
                    ->url(fn (AssetUsage $record): ?string => match (true) {
                        $record->usable instanceof Page => PageResource::editorUrl($record->usable),
                        $record->usable instanceof Redirect => RedirectResource::getUrl('index', tenant: $record->usable->site),
                        default => null,
                    }),
            ])
            ->emptyStateIcon('phosphor-link-break')
            ->emptyStateHeading(__('Not used anywhere yet'))
            ->emptyStateDescription(__('Pick it from any image field in the visual editor.'));
    }
}
