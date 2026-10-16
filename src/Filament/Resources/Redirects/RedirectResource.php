<?php

namespace Aurora\Filament\Resources\Redirects;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Resources\Redirects\Pages\ManageRedirects;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Support\CurrentSite;
use Aurora\Support\SearchThumbnail;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class RedirectResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-arrow-u-up-right';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'from_path';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('from_path')
                    ->label(__('Old address'))
                    ->prefix(fn (): string => CurrentSite::url('/'))
                    ->required()
                    // Unique on this site: another site may redirect the same address.
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('site_id', CurrentSite::id()))
                    ->dehydrateStateUsing(fn (string $state): string => Redirect::normalisePath($state)),
                ToggleButtons::make('target')
                    ->label(__('Send visitors to'))
                    ->options(['page' => __('A page'), 'file' => __('A file'), 'url' => __('A URL')])
                    ->icons(['page' => 'phosphor-file-text', 'file' => 'phosphor-paperclip', 'url' => 'phosphor-link'])
                    ->default('page')
                    ->inline()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (ToggleButtons $component, ?Redirect $record) => $component->state(match (true) {
                        filled($record?->asset_id) => 'file',
                        filled($record?->to_url) => 'url',
                        default => 'page',
                    })),
                Select::make('page_id')
                    ->label(__('Page'))
                    ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->helperText(__('Follows the page if its address changes again.'))
                    ->visible(fn (Get $get): bool => $get('target') === 'page')
                    ->required(fn (Get $get): bool => $get('target') === 'page')
                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $get('target') === 'page' ? $state : null),
                AssetPicker::make('asset_id')
                    ->label(__('File'))
                    ->anyFile()
                    ->helperText(__('Keeps working when the file is replaced in the media library.'))
                    ->visible(fn (Get $get): bool => $get('target') === 'file')
                    ->required(fn (Get $get): bool => $get('target') === 'file')
                    ->dehydrateStateUsing(fn (mixed $state, Get $get): ?string => $get('target') === 'file' && is_string($state) ? $state : null),
                TextInput::make('to_url')
                    ->label(__('URL'))
                    ->url()
                    ->visible(fn (Get $get): bool => $get('target') === 'url')
                    ->required(fn (Get $get): bool => $get('target') === 'url')
                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $get('target') === 'url' ? $state : null),
                ToggleButtons::make('status_code')
                    ->label(__('Type'))
                    ->options([301 => __('Permanent (301)'), 302 => __('Temporary (302)')])
                    ->default(301)
                    ->inline()
                    ->grouped(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['page' => fn ($query) => $query->withTrashed(), 'asset.media']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('from_path')->label(__('From'))->fontFamily('mono')->searchable()->copyable(),
                TextColumn::make('destination')
                    ->label(__('To'))
                    ->state(fn (Redirect $record): ?string => match (true) {
                        // A trashed page's redirect leads nowhere until the page is restored.
                        $record->page?->trashed() => __(':page (in the trash)', ['page' => $record->page->title]),
                        $record->page !== null => $record->page->title.' ('.$record->page->path().')',
                        $record->asset !== null => $record->asset->title,
                        default => $record->to_url,
                    })
                    ->color(fn (Redirect $record): ?string => $record->page?->trashed() ? 'danger' : null)
                    ->icon(fn (Redirect $record): string => match (true) {
                        $record->page !== null => 'phosphor-file-text',
                        $record->asset !== null => $record->asset->type()->getIcon(),
                        default => 'phosphor-link',
                    }),
                TextColumn::make('status_code')->label(__('Type'))->badge()->color(fn (int $state): string => $state === 301 ? 'success' : 'warning'),
                TextColumn::make('hits')->numeric()->sortable()->alignEnd(),
                TextColumn::make('last_hit_at')->label(__('Last used'))->since()->placeholder(__('Never'))->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateIcon('phosphor-arrow-u-up-right')
            ->emptyStateHeading(__('No redirects yet'))
            ->emptyStateDescription(__('Redirects are created automatically when a live page changes address.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRedirects::route('/'),
        ];
    }

    /**
     * In global search, with the same icon as in the sidebar.
     */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return SearchThumbnail::title(strip_tags((string) static::getRecordTitle($record)), null, icon: static::getNavigationIcon());
    }
}
