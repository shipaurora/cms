<?php

namespace Aurora\Filament\Resources\Pages\Schemas;

use Aurora\Enums\PageLayout;
use Aurora\Enums\PageStatus;
use Aurora\Filament\Forms\Components\AssetPicker;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\UserBadge;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        self::pageSection(),
                        self::contentSection(),
                        self::seoTabs(),
                    ]),
                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        self::publishingSection(),
                        self::languagesSection(),
                        self::layoutSection(),
                        self::detailsSection(),
                    ]),
            ]);
    }

    protected static function pageSection(): Section
    {
        return Section::make(__('Page'))
            ->icon('phosphor-file-text')
            ->description(__('What the page is called and where it lives.'))
            ->schema([
                TextInput::make('title')
                    ->withAi('The page\'s headline title, shown in menus and the browser tab. Short and specific.')
                    ->required()
                    ->maxLength(255)
                    ->autofocus()
                    ->placeholder(__('e.g. About us'))
                    ->extraInputAttributes(['class' => 'text-lg font-semibold'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set, string $operation): void {
                        if ($operation === Operation::Create->value && blank($get('slug_touched'))) {
                            $set('slug', filled($state) ? Page::uniqueSlug($state) : null);
                        }
                    }),
                Select::make('parent_id')
                    ->label(__('Parent page'))
                    ->placeholder(__('None: a top-level page'))
                    // Any page but the homepage (its pages sit at the top anyway), this one and
                    // the pages below it.
                    ->options(fn (?Page $record): array => Page::options(fn (Builder $query) => $query
                        ->where('is_homepage', false)
                        ->when($record, fn (Builder $query) => $query->whereKeyNot([$record->getKey(), ...$record->descendantIds()]))))
                    ->searchable()
                    ->live()
                    ->prefixIcon('phosphor-tree-structure')
                    ->helperText(__('A subpage’s address starts with its parent’s, like /docs/getting-started.'))
                    // Not for the homepage, nor while there is no other page to sit under (the
                    // homepage doesn't count: its pages sit at the top anyway).
                    ->hidden(fn (?Page $record, Get $get): bool => (bool) ($record?->is_homepage || $get('is_homepage'))
                        || ! Page::query()->where('is_homepage', false)->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))->exists()),
                TextInput::make('slug')
                    ->label(__('Address'))
                    // The address in the language being edited, under the parent page: …/nb/dokumentasjon/kom-i-gang.
                    ->prefix(fn (Get $get): string => Str::of(CurrentSite::url(ContentLocale::prefix()))->after('://')->rtrim('/')
                        ->append(static::parentPath($get('parent_id')).'/')
                        ->toString())
                    ->helperText(null)
                    ->maxLength(120)
                    ->alphaDash()
                    ->notIn(Page::RESERVED_SLUGS)
                    ->validationMessages(['not_in' => __('This address is reserved by the application.')])
                    // Unique among the addresses in the language being edited.
                    ->rule(fn (?Page $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if (is_string($value) && ! Page::slugIsAvailable($value, except: $record?->getKey())) {
                            $fail(__('Another page already has this address.'));
                        }
                    })
                    ->afterStateUpdated(fn (Set $set) => $set('slug_touched', true))
                    ->live(onBlur: true)
                    ->helperText(fn (?Page $record): ?string => $record?->isLive()
                        ? __('When you change the address of a live page, saving asks whether the old address should redirect here.')
                        : null)
                    ->suffixAction(
                        Action::make('regenerateSlug')
                            ->icon('phosphor-arrows-clockwise')
                            ->tooltip(__('Generate from title'))
                            ->action(fn (Get $get, Set $set, ?Page $record) => $set('slug', Page::uniqueSlug((string) $get('title'), except: $record?->getKey()))),
                    ),
                TextInput::make('slug_touched')->hidden()->dehydrated(false),
                Textarea::make('excerpt')
                    ->label(__('Summary'))
                    ->withAi('One or two sentences that sum the page up, shown in page lists and as a fallback for search engines.')
                    ->rows(2)
                    ->maxLength(300)
                    ->helperText(__('Shown in page lists and used as a fallback description for search engines.')),
                Select::make('start_from')
                    ->label(__('Start from'))
                    ->visibleOn(Operation::Create)
                    ->dehydrated(false)
                    ->placeholder(__('A blank canvas'))
                    ->helperText(__('Copy the blocks of an existing page as a starting point.'))
                    ->options(fn (): array => Page::options(fn ($query) => $query->whereNotNull('blocks')))
                    ->searchable()
                    ->prefixIcon('phosphor-copy-simple'),
            ]);
    }

    /**
     * The part of the address a subpage of this page starts with ("/docs"), in the language
     * being edited.
     */
    protected static function parentPath(?string $parentId): string
    {
        $parent = filled($parentId) ? Page::query()->find($parentId) : null;

        return $parent === null || $parent->is_homepage ? '' : Str::after($parent->path(), ContentLocale::prefix());
    }

    protected static function contentSection(): Section
    {
        return Section::make(__('Content'))
            ->icon('phosphor-paint-brush')
            ->description(__('Pages are composed visually, on the page itself.'))
            ->visibleOn(Operation::Edit)
            ->headerActions([
                Action::make('design')
                    ->label(__('Open visual editor'))
                    ->icon('phosphor-paint-brush')
                    // In the language being edited: another language opens the editor to translate.
                    ->url(fn (Page $record): string => PageResource::getUrl('design', ['record' => $record, ...(ContentLocale::current() === $record->primaryLocale() ? [] : ['language' => ContentLocale::current()])]))
                    ->visible(fn (Page $record): bool => PageResource::canEdit($record))
                    ->keyBindings(['mod+e']),
            ])
            ->schema([
                View::make('aurora::filament.pages.content-overview'),
            ]);
    }

    protected static function seoTabs(): Tabs
    {
        return Tabs::make('Discoverability')
            ->persistTabInQueryString()
            ->tabs([
                Tab::make(__('Search engines'))
                    ->icon('phosphor-magnifying-glass')
                    ->schema([
                        View::make('aurora::filament.pages.serp-preview')
                            ->viewData(fn (Get $get, ?Page $record): array => [
                                'title' => filled($get('meta_title')) ? $get('meta_title') : $get('title'),
                                'description' => filled($get('meta_description')) ? $get('meta_description') : $get('excerpt'),
                                // The homepage flag isn't in the form after switching language: the record has it.
                                'path' => ltrim(ContentLocale::prefix().(($get('is_homepage') || $record?->is_homepage) ? '' : static::parentPath($get('parent_id')).'/'.$get('slug')), '/'),
                            ]),
                        TextInput::make('meta_title')
                            ->label(__('SEO title'))
                            ->withAi('The title search engines show: about 50-60 characters, the page\'s main topic first.')
                            ->placeholder(fn (Get $get): ?string => $get('title'))
                            ->maxLength(70)
                            ->live(debounce: 300)
                            ->hint(fn (?string $state): string => Str::length((string) $state).' / 60')
                            ->hintColor(fn (?string $state): string => Str::length((string) $state) > 60 ? 'danger' : 'gray'),
                        Textarea::make('meta_description')
                            ->label(__('Meta description'))
                            ->withAi('The description search engines show under the title: about 150-160 characters, inviting a click.')
                            ->placeholder(fn (Get $get): ?string => $get('excerpt'))
                            ->rows(3)
                            ->maxLength(200)
                            ->live(debounce: 300)
                            ->hint(fn (?string $state): string => Str::length((string) $state).' / 155')
                            ->hintColor(fn (?string $state): string => Str::length((string) $state) > 155 ? 'danger' : 'gray'),
                        Toggle::make('noindex')
                            ->label(__('Hide from search engines'))
                            ->helperText(__('Adds noindex and leaves the page out of the sitemap.')),
                    ]),
                Tab::make(__('Social sharing'))
                    ->icon('phosphor-share-network')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            AssetPicker::make('og_image')
                                ->label(__('Share image'))
                                ->helperText(__('Cropped to 1200 × 630 for social networks.'))
                                ->live(),
                            View::make('aurora::filament.pages.social-preview')
                                ->viewData(fn (Get $get): array => [
                                    'title' => filled($get('meta_title')) ? $get('meta_title') : $get('title'),
                                    'description' => filled($get('meta_description')) ? $get('meta_description') : $get('excerpt'),
                                    'image' => $get('og_image'),
                                ]),
                        ]),
                    ]),
            ]);
    }

    protected static function publishingSection(): Section
    {
        return Section::make(__('Publishing'))
            ->icon('phosphor-rocket-launch')
            ->schema([
                ToggleButtons::make('status')
                    ->hiddenLabel()
                    ->options(PageStatus::class)
                    // The site's first page is its homepage, so it starts published (when the
                    // person may publish); later pages start as drafts.
                    ->default(fn (): PageStatus => ! Page::othersExist() && auth()->user()->can('publish', Page::class) ? PageStatus::Published : PageStatus::Draft)
                    ->disableOptionWhen(fn (string $value, ?Page $record): bool => ($value === PageStatus::Published->value
                        && ! auth()->user()->can('publish', $record ?? Page::class))
                        // The homepage stays live; make another page the homepage first.
                        || ($value !== PageStatus::Published->value && (bool) $record?->getOriginal('is_homepage')))
                    ->required()
                    ->live()
                    ->grouped()
                    ->extraAttributes(['class' => 'cms-toggle-buttons-full']),
                Callout::make(__('You can send pages for review. An editor publishes them.'))
                    ->info()
                    ->visible(fn (?Page $record): bool => ! auth()->user()->can('publish', $record ?? Page::class)),
                DateTimePicker::make('published_at')
                    ->label(__('Publish date'))
                    ->native(false)
                    ->seconds(false)
                    ->prefixIcon('phosphor-calendar-dots')
                    ->helperText(fn (Get $get): string => $get('published_at') && now()->lt($get('published_at'))
                        ? __('Scheduled: the page goes live automatically at this time.')
                        : __('Leave empty to publish immediately.'))
                    ->live()
                    ->visible(fn (Get $get): bool => $get('status') === PageStatus::Published || $get('status') === PageStatus::Published->value),
                Toggle::make('is_homepage')
                    ->label(__('Use as homepage'))
                    // The site's first page is its homepage.
                    ->default(fn (): bool => ! Page::othersExist())
                    ->live()
                    // The current homepage can't be switched off, only replaced by another page.
                    ->disabled(fn (?Page $record): bool => (bool) $record?->getOriginal('is_homepage'))
                    ->helperText(function (?Page $record, Get $get): string {
                        if ($record?->getOriginal('is_homepage')) {
                            return __('This is the homepage. To change it, switch this on for another page.');
                        }

                        $current = Page::query()->homepage()->whereKeyNot($record?->getKey())->value('title');

                        return $get('is_homepage') && $current
                            ? __('Replaces “:current” at :url when you save.', ['current' => $current, 'url' => CurrentSite::url(ContentLocale::prefix() ?: '/')])
                            : __('Serve this page at :url.', ['url' => CurrentSite::url(ContentLocale::prefix() ?: '/')]);
                    })
                    ->rule(fn (Get $get, ?Page $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        // The site's only page may be its homepage before it is live.
                        if (! Page::othersExist($record?->getKey())) {
                            return;
                        }

                        $status = $get('status');
                        $status = $status instanceof PageStatus ? $status : PageStatus::tryFrom((string) $status);
                        $publishedAt = $get('published_at');

                        if ($value && ($status !== PageStatus::Published || ($publishedAt && now()->lt($publishedAt)))) {
                            $fail(__('The homepage must be live. Set the status to Published (with no future date) to use this page as the homepage.'));
                        }
                    }),
            ]);
    }

    /**
     * Which languages the page is in: any of them, at least one. With one language in the
     * CMS there is nothing to choose, so the field is hidden and that language is stored.
     * Live, so the language switchers follow the ticks before saving.
     */
    protected static function languagesSection(): Section
    {
        $all = fn (): array => array_keys(ContentLocale::all()) ?: [ContentLocale::default()];

        return Section::make(__('Languages'))
            ->icon('phosphor-translate')
            ->collapsible()
            ->visible(fn (): bool => count(ContentLocale::all()) > 1)
            ->schema([
                CheckboxList::make('locales')
                    ->hiddenLabel()
                    ->options(fn (): array => ContentLocale::all())
                    ->descriptions(fn (): array => [ContentLocale::default() => __('Default site language')])
                    ->helperText(__('Visitors can only open the page in these languages. The first one ticked is the language the page is written in; the others are translations of it.'))
                    ->required()
                    ->minItems(1)
                    ->live()
                    ->default(fn (): array => $all())
                    // Pages from before languages could be chosen are in all of them.
                    ->afterStateHydrated(fn (CheckboxList $component, ?Page $record) => $record && $record->locales === null ? $component->state($all()) : null)
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (?array $state): array => array_values(array_intersect($all(), $state ?: $all()))),
            ]);
    }

    protected static function layoutSection(): Section
    {
        return Section::make(__('Layout'))
            ->icon('phosphor-app-window')
            ->collapsible()
            ->schema([
                Radio::make('layout')
                    ->hiddenLabel()
                    ->options(PageLayout::class)
                    ->default(PageLayout::Default),
            ]);
    }

    protected static function detailsSection(): Section
    {
        return Section::make(__('Details'))
            ->icon('phosphor-info')
            ->visibleOn(Operation::Edit)
            ->collapsible()
            ->compact()
            ->schema([
                TextEntry::make('url')
                    ->label(__('Address'))
                    ->state(fn (Page $record): string => $record->url())
                    ->url(fn (Page $record): ?string => $record->isLive() ? $record->url() : null, shouldOpenInNewTab: true)
                    ->icon('phosphor-arrow-square-out')
                    ->iconPosition('after')
                    ->copyable(),
                TextEntry::make('author.name')
                    ->label(__('Created by'))
                    ->html()
                    ->formatStateUsing(fn (?Page $record): ?HtmlString => UserBadge::html($record?->author))
                    ->placeholder(__('Unknown')),
                TextEntry::make('updated_at')
                    ->label(__('Last edited'))
                    ->state(fn (Page $record): string => $record->updated_at->diffForHumans())
                    ->belowContent(fn (?Page $record): ?HtmlString => UserBadge::html($record?->lastEditor)),
                TextEntry::make('revisions_count')
                    ->label(__('Revisions'))
                    ->state(fn (Page $record): int => $record->revisions()->count())
                    ->badge(),
            ]);
    }
}
