<?php

namespace Aurora\Filament\Resources\Pages;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Filament\Resources\Pages\RelationManagers\RevisionsRelationManager;
use Aurora\Filament\Resources\Pages\Schemas\PageForm;
use Aurora\Filament\Resources\Pages\Tables\PagesTable;
use Aurora\Models\Page;
use Aurora\Support\CurrentSite;
use Aurora\Support\AssetLibrary;
use Aurora\Support\ContentLocale;
use Aurora\Support\SearchThumbnail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;
use UnitEnum;

class PageResource extends Resource
{
    use Translatable;

    /**
     * Every language in Settings → Languages, the default site language first.
     *
     * @return array<int, string>
     */
    public static function getTranslatableLocales(): array
    {
        return array_keys(ContentLocale::all()) ?: [ContentLocale::default()];
    }

    use TranslatesResourceLabels;

    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-file-text';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-file-text-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RevisionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
            'design' => DesignPage::route('/{record}/design'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $inReview = once(fn (): int => Page::query()->where('status', PageStatus::Review)->count());

        return $inReview > 0 ? (string) $inReview : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Waiting for review');
    }

    /**
     * What visitors see at the homepage's address while it isn't live (drafted, in review or
     * in the trash): "Page not found", until it is live again or another page becomes the
     * homepage. No other page stands in for it.
     */
    public static function withoutHomepage(Page $homepage): string
    {
        return __('“:page” is the homepage. Until it’s published again, or another page becomes the homepage, visitors to :url see “Page not found”.', ['page' => $homepage->title, 'url' => CurrentSite::url(ContentLocale::prefix() ?: '/')]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'slug', 'excerpt'];
    }

    /**
     * With the page's share image in front, or the pages icon when it has none.
     */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Page $record */
        return SearchThumbnail::title($record->title, filled($record->og_image) ? AssetLibrary::url($record->og_image, 'thumb') : null, icon: static::getNavigationIcon());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Page $record */
        return [
            'Status' => $record->status->getLabel(),
            'Address' => $record->path(),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('edit', ['record' => $record]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * The visual editor for a page, on the page's own site (possibly not the one being
     * worked on).
     */
    public static function editorUrl(Page $page): string
    {
        return static::getUrl('design', ['record' => $page], panel: 'admin', tenant: $page->site);
    }

    /**
     * The page's settings, on its own site: for links from outside the admin (the website's
     * edit bar, AI apps) too, where no site is picked.
     */
    public static function settingsUrl(Page $page): string
    {
        return static::getUrl('edit', ['record' => $page], panel: 'admin', tenant: $page->site);
    }
}
