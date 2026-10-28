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
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

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
                        // A website's own record (an event, a product): named as its resource names it.
                        $record->usable instanceof Model => static::recordTitle($record->usable),
                        default => null,
                    })
                    ->description(fn (AssetUsage $record): ?string => match (true) {
                        $record->usable instanceof Page, $record->usable instanceof Redirect => collect([
                            Site::query()->count() > 1 ? $record->usable->site?->name : null,
                            // On its site's domain, as the pages list shows it.
                            $record->usable instanceof Page ? CurrentSite::using($record->usable->site, fn (): string => Str::after(CurrentSite::url($record->usable->path()), '://')) : null,
                        ])->filter()->join(' · ') ?: null,
                        // What kind of record it is: "Event".
                        $record->usable instanceof Model => ($resource = static::resourceFor($record->usable)) ? Str::ucfirst($resource::getModelLabel()) : Str::headline(class_basename($record->usable)),
                        default => null,
                    }),
                TextColumn::make('location')
                    ->label(__('Where'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => AssetLibrary::describeLocation($state)),
                TextColumn::make('updated_at')->label(__('Since'))->since()->color('gray'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(fn (AssetUsage $record): string => match (true) {
                        $record->usable instanceof Redirect => __('Open redirects'),
                        $record->usable instanceof Page => __('Open page'),
                        default => __('Open'),
                    })
                    ->icon(fn (AssetUsage $record): string => $record->usable instanceof Redirect ? 'phosphor-arrow-u-up-right' : 'phosphor-file-text')
                    // The page's settings, on its own site: the visual editor is a click away from there.
                    ->url(fn (AssetUsage $record): ?string => match (true) {
                        $record->usable instanceof Page => PageResource::getUrl('edit', ['record' => $record->usable], tenant: $record->usable->site),
                        $record->usable instanceof Redirect => RedirectResource::getUrl('index', tenant: $record->usable->site),
                        $record->usable instanceof Model => static::recordUrl($record->usable),
                        default => null,
                    })
                    ->visible(fn (AssetUsage $record): bool => $record->usable instanceof Page || $record->usable instanceof Redirect || ($record->usable instanceof Model && static::recordUrl($record->usable) !== null)),
            ])
            ->emptyStateIcon('phosphor-link-break')
            ->emptyStateHeading(__('Not used anywhere yet'))
            ->emptyStateDescription(__('Pick it from any image field in the visual editor.'));
    }

    /**
     * The admin screen for a website's own model (AdminPanel::resources()), if it has one.
     *
     * @return class-string<\Filament\Resources\Resource>|null
     */
    protected static function resourceFor(Model $model): ?string
    {
        return rescue(fn (): ?string => Filament::getModelResource($model), null, report: false);
    }

    /**
     * A website's record as its resource titles it, else its title, name or kind and key.
     */
    protected static function recordTitle(Model $model): string
    {
        // Only a resource that names its records ($recordTitleAttribute): otherwise Filament
        // titles every record with the model's label.
        $title = ($resource = static::resourceFor($model)) && filled($resource::getRecordTitleAttribute()) ? $resource::getRecordTitle($model) : null;
        $title = filled($title) ? strip_tags((string) $title) : null;

        return $title
            ?? collect(['title', 'name', 'label'])->map(fn (string $attribute): mixed => $model->getAttributeValue($attribute))->first(fn (mixed $value): bool => is_string($value) && filled($value))
            ?? Str::headline(class_basename($model)).' '.$model->getKey();
    }

    /**
     * Where to open a website's record: its resource's edit page, else its view page, when the
     * person may.
     */
    protected static function recordUrl(Model $model): ?string
    {
        $resource = static::resourceFor($model);

        return rescue(fn (): ?string => match (true) {
            $resource === null => null,
            $resource::hasPage('edit') && $resource::canEdit($model) => $resource::getUrl('edit', ['record' => $model]),
            $resource::hasPage('view') && $resource::canView($model) => $resource::getUrl('view', ['record' => $model]),
            default => null,
        }, null, report: false);
    }
}
