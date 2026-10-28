<?php

namespace Aurora\Filament\Resources\Assets;

use Aurora\Enums\AssetType;
use Aurora\Filament\Actions\AccessAction;
use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Forms\Components\FolderSelect;
use Aurora\Filament\Forms\Components\WriteWithAi;
use Aurora\Filament\Resources\Assets\Pages\EditAsset;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Assets\Pages\ViewAsset;
use Aurora\Filament\Resources\Assets\RelationManagers\UsagesRelationManager;
use Aurora\Filament\Resources\Assets\RelationManagers\VariantsRelationManager;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\AssetTag;
use Aurora\Support\AssetAccess;
use Aurora\Support\AudioMetadata;
use Aurora\Support\ContentLocale;
use Aurora\Support\ImageDescriptions;
use Aurora\Support\ImageMetadata;
use Aurora\Support\ImageTags;
use Aurora\Support\SearchThumbnail;
use Aurora\Support\UserBadge;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class AssetResource extends Resource
{
    use Translatable;
    use TranslatesResourceLabels;

    protected static ?string $model = Asset::class;

    /**
     * Shared by every site: not tied to the site picked in the admin.
     */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-images';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-images-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Media library';

    protected static ?string $modelLabel = 'file';

    protected static ?string $breadcrumb = 'Media library';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * @return array<int, string>
     */
    public static function getTranslatableLocales(): array
    {
        return array_keys(ContentLocale::all()) ?: [ContentLocale::default()];
    }

    /**
     * The default site language's text, shown in an empty box while another language is edited.
     */
    protected static function defaultLanguageText(?Asset $record, string $attribute): ?string
    {
        return $record?->getTranslation($attribute, ContentLocale::default(), false) ?: null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()->columnSpan(['lg' => 2])->schema([
                    Section::make()
                        ->schema([View::make('aurora::filament.assets.preview')]),
                    // Where crops keep the subject: for images that are cropped (not SVG and the like).
                    Section::make(__('Focal point'))
                        ->icon('phosphor-crosshair')
                        ->collapsible()
                        ->collapsed(fn (?Asset $record): bool => blank($record?->focal_point))
                        ->visible(fn (?Asset $record): bool => in_array($record?->mime_type, Asset::RASTER_MIME_TYPES, true))
                        ->schema([
                            ViewField::make('focal_point')
                                ->hiddenLabel()
                                ->view('aurora::filament.assets.focal-point'),
                        ]),
                    Section::make(__('Description'))
                        ->icon('phosphor-chat-text')
                        ->description(fn (): string => count(ContentLocale::all()) > 1
                            ? __('Used everywhere this file appears. Alt text and caption are written per language; empty ones show the :language text.', ['language' => ContentLocale::all()[ContentLocale::default()] ?? ''])
                            : __('Written once, used everywhere this file appears.'))
                        ->schema([
                            TextInput::make('title')->required()->maxLength(255)
                                ->withAi('A short, descriptive name for this file in the media library.')
                                ->withAiImage(fn (Asset $record): Asset => $record),
                            TextInput::make('alt')
                                ->label(__('Alt text'))
                                ->withAi('Alt text for screen readers: what the image shows, in one plain sentence, without "image of".', language: fn ($livewire): ?string => $livewire->activeLocale ?? null)
                                ->withAiImage(fn (Asset $record): Asset => $record)
                                ->withoutAiDialog()
                                ->visible(fn (?Asset $record): bool => $record?->isImage() ?? true)
                                ->maxLength(255)
                                ->placeholder(fn (?Asset $record): ?string => static::defaultLanguageText($record, 'alt'))
                                ->helperText(__('What a screen reader says instead of showing the image. Leave empty only for purely decorative images.')),
                            Textarea::make('caption')->rows(2)->maxLength(500)
                                ->placeholder(fn (?Asset $record): ?string => static::defaultLanguageText($record, 'caption'))
                                ->withAi('A caption shown under the file where it is used.', language: fn ($livewire): ?string => $livewire->activeLocale ?? null)
                                ->withAiImage(fn (Asset $record): Asset => $record),
                            TextInput::make('credit')->label(__('Credit'))->placeholder(__('Photo: Name / Agency'))->maxLength(255),
                        ]),
                ]),
                Group::make()->columnSpan(['lg' => 1])->schema([
                    Section::make(__('Organise'))
                        ->icon('phosphor-folder')
                        ->schema([
                            FolderSelect::make('folder_id'),
                            static::tagsSelect(),
                        ]),
                    Section::make(__('File'))
                        ->icon('phosphor-file')
                        ->compact()
                        ->schema([
                            TextEntry::make('file_name')->label(__('File'))->state(fn (Asset $record): ?string => $record->file()?->file_name),
                            TextEntry::make('type')
                                ->label(__('Type'))
                                ->state(fn (Asset $record): string => $record->type()->getLabel())
                                ->icon(fn (Asset $record): string => $record->type()->getIcon())
                                ->belowContent(fn (Asset $record): ?string => $record->mime_type),
                            TextEntry::make('dimensions')
                                ->state(fn (Asset $record): ?string => ($d = $record->dimensions())['width'] ? "{$d['width']} × {$d['height']} px" : null)
                                ->placeholder(__('Unknown'))
                                ->visible(fn (Asset $record): bool => $record->isImage()),
                            TextEntry::make('size')->state(fn (Asset $record): ?string => $record->file() ? Number::fileSize($record->file()->size, 1) : null),
                            TextEntry::make('uploaded')->state(fn (Asset $record): string => $record->created_at->isoFormat('ll'))->belowContent(fn (Asset $record): ?HtmlString => UserBadge::html($record->uploader)),
                            // Its address to copy, and a button that plainly opens it.
                            TextEntry::make('url')
                                ->label(__('Original'))
                                ->state(fn (Asset $record): ?string => $record->url(null))
                                ->copyable()
                                ->limit(40)
                                ->belowContent(fn (Asset $record): Action => Action::make('openOriginal')
                                    ->label(__('Open original'))
                                    ->icon('phosphor-arrow-square-out')
                                    ->color('gray')
                                    ->size('sm')
                                    ->url($record->url(null), shouldOpenInNewTab: true)),
                        ]),
                    // What the camera wrote into the file: for the formats that carry EXIF PHP can read,
                    // when the file has some.
                    Section::make(__('EXIF data'))
                        ->icon('phosphor-camera')
                        ->compact()
                        ->collapsible()
                        ->visible(fn (?Asset $record): bool => ImageMetadata::has($record))
                        ->schema([View::make('aurora::filament.assets.metadata')->viewData(['reader' => ImageMetadata::class])]),
                    // The same for an audio file's tags: ID3 in an MP3.
                    Section::make(fn (?Asset $record): string => AudioMetadata::isId3($record) ? __('ID3 tags') : __('Audio tags'))
                        ->icon('phosphor-music-notes')
                        ->compact()
                        ->collapsible()
                        ->visible(fn (?Asset $record): bool => AudioMetadata::has($record))
                        ->schema([View::make('aurora::filament.assets.metadata')->viewData(['reader' => AudioMetadata::class])]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // In the media library, the grid shows the open folder; a search or tag filter looks everywhere.
            ->modifyQueryUsing(fn (Builder $query, $livewire) => $query
                // Only the files this person may read (AssetAccess).
                ->tap(fn (Builder $query) => AssetAccess::scopeReadable($query, auth()->user()))
                ->with(['media', 'folder', 'tags'])
                ->when(
                    $livewire instanceof ListAssets && ! $livewire->isLookingEverywhere(),
                    fn (Builder $query) => $query->where('folder_id', $livewire->folder),
                ))
            ->defaultSort('created_at', 'desc')
            ->contentGrid(['default' => 2, 'md' => 3, 'xl' => 5])
            // Picked up by the folder browser: cards can be dragged onto folders.
            // overflow-hidden/min-w-0: long unbroken titles (e.g. camera file names) can't widen a card.
            // Edit for those who may change it, view-only otherwise (Filament would try view first).
            ->recordUrl(fn (Asset $record): ?string => static::urlFor($record))
            // Only files someone may change can be selected, so a read-only file offers no actions;
            // with none on the page, there's nothing to select and no bulk actions.
            ->checkIfRecordIsSelectableUsing(fn (Asset $record): bool => auth()->user()->can('update', $record))
            ->selectable(fn ($livewire): bool => $livewire->getTableRecords()->contains(fn (Asset $record): bool => auth()->user()->can('update', $record)))
            ->recordClasses(['cms-asset-record', 'overflow-hidden', '[&_.fi-ta-record-content-ctn]:min-w-0', '[&_.fi-ta-record-content]:min-w-0'])
            ->paginated([20, 40, 80])
            ->defaultPaginationPageOption(40)
            ->columns([
                Stack::make([
                    ViewColumn::make('thumbnail')
                        ->view('aurora::filament.assets.thumbnail')
                        ->extraAttributes(['class' => 'w-full']),
                    TextColumn::make('title')
                        // A lock when the file has its own access.
                        ->icon(fn (Asset $record): ?string => $record->access === AssetAccess::CUSTOM ? 'phosphor-lock-key-fill' : null)
                        ->iconPosition('after')
                        ->iconColor('gray')
                        ->weight(FontWeight::SemiBold)
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search))
                        ->description(fn (Asset $record, $livewire): ?string => $livewire instanceof ListAssets && $livewire->isLookingEverywhere() ? $record->folder?->path() : null)
                        ->limit(32)
                        ->extraAttributes(['class' => '[overflow-wrap:anywhere]']),
                    TextColumn::make('alt')
                        ->state(fn (Asset $record): ?string => $record->isImage() ? ($record->alt ?: __('Missing alt text')) : $record->type()->getLabel())
                        ->color(fn (Asset $record): string => $record->isImage() && blank($record->alt) ? 'warning' : 'gray')
                        ->size('xs')
                        ->limit(40),
                    ViewColumn::make('tags')->view('aurora::filament.assets.tag-dots'),
                ])->space(2),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(AssetType::class)
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => $query->ofType(...array_map(AssetType::from(...), $data['values'] ?? []))),
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->getOptionLabelFromRecordUsing(fn (AssetTag $tag): string => static::tagLabel($tag))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->allowHtml())
                    // The options carry a coloured dot (HTML); the active-filter chip lists plain names.
                    ->indicateUsing(function (array $state): ?string {
                        $names = AssetTag::query()->whereKey($state['values'] ?? [])->orderBy('name')->pluck('name');

                        return $names->isEmpty() ? null : __('Tags').': '.$names->implode(', ');
                    })
                    ->multiple()
                    ->preload()
                    ->searchable(),
                // Files by one photographer or agency: any part of the credit, whatever its capitals.
                Filter::make('credit')
                    ->schema([
                        TextInput::make('credit')
                            ->label(__('Credit'))
                            ->placeholder(__('Name of the photographer or agency')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($credit = trim((string) ($data['credit'] ?? '')))
                        ? $query->whereLike('credit', '%'.$credit.'%')
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['credit'] ?? null) ? __('Credit').': '.trim($data['credit']) : null),
                TernaryFilter::make('in_use')
                    ->label(__('Usage'))
                    ->trueLabel(__('In use'))
                    ->falseLabel(__('Not used anywhere'))
                    ->queries(true: fn (Builder $query) => $query->has('usages'), false: fn (Builder $query) => $query->doesntHave('usages')),
                TernaryFilter::make('alt')
                    ->label(__('Alt text'))
                    ->trueLabel(__('Has alt text'))
                    ->falseLabel(__('Missing alt text'))
                    // Alt text is for images; other files have none to miss.
                    ->queries(
                        true: fn (Builder $query) => $query->ofType(AssetType::Image)->whereNotNull('alt')->where('alt', '!=', ''),
                        false: fn (Builder $query) => $query->ofType(AssetType::Image)->where(fn ($query) => $query->whereNull('alt')->orWhere('alt', '')),
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('move')
                        ->label(__('Move to folder'))
                        ->icon('phosphor-folder-simple')
                        ->schema([
                            FolderSelect::make('folder_id'),
                        ])
                        // Into a folder they may add to, and only the files they may change.
                        ->action(function (Collection $records, array $data, BulkAction $action): void {
                            $target = filled($data['folder_id'] ?? null) ? AssetFolder::query()->find($data['folder_id']) : null;

                            if (! AssetAccess::canWrite(auth()->user(), $target)) {
                                Notification::make()->title(__('You can’t add files to this folder.'))->warning()->send();
                                $action->halt();
                            }

                            $records->filter(fn (Asset $asset): bool => auth()->user()->can('update', $asset))->each->update(['folder_id' => $target?->id]);
                        })
                        ->deselectRecordsAfterCompletion()
                        ->successNotificationTitle(__('Moved')),
                    BulkAction::make('credit')
                        ->label(__('Edit credit'))
                        ->icon('phosphor-copyright')
                        ->modalWidth('md')
                        // Their shared credit to start from, when they all have the same one.
                        ->fillForm(fn (Collection $records): array => ['credit' => $records->pluck('credit')->unique()->count() === 1 ? $records->first()->credit : null])
                        ->schema([
                            TextInput::make('credit')
                                ->label(__('Credit'))
                                ->placeholder(__('Photo: Name / Agency'))
                                ->maxLength(255)
                                ->helperText(__('Replaces the credit on every selected file. Leave it empty to remove it.')),
                        ])
                        // Only the files they may change.
                        ->action(fn (Collection $records, array $data) => $records
                            ->filter(fn (Asset $asset): bool => auth()->user()->can('update', $asset))
                            ->each->update(['credit' => filled($data['credit'] ?? null) ? $data['credit'] : null]))
                        ->deselectRecordsAfterCompletion()
                        ->successNotificationTitle(__('Credit saved')),
                    // Alt text for the selected images that have none, on the queue (ImageDescriptions).
                    BulkAction::make('writeAltText')
                        ->label(__('Write alt text with AI'))
                        ->icon('phosphor-sparkle')
                        ->visible(fn (): bool => WriteWithAi::isAvailable())
                        ->modalHeading(__('Write alt text with AI'))
                        ->modalDescription(__('The assistant writes alt text for the selected images that have none. Images that already have alt text are left as they are.'))
                        ->modalIcon('phosphor-sparkle')
                        ->modalWidth('md')
                        ->modalSubmitActionLabel(__('Write it'))
                        ->schema([
                            Textarea::make('instruction')
                                ->label(__('Instruction (optional)'))
                                ->placeholder(__('e.g. these are from the spring workshop in Bergen'))
                                ->rows(2)
                                ->maxLength(500),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = ImageDescriptions::queueFor(
                                $records->filter(fn (Asset $asset): bool => auth()->user()->can('update', $asset)),
                                filled($data['instruction'] ?? null) ? $data['instruction'] : null,
                            );

                            $count === 0
                                ? Notification::make()->title(__('All the selected images already have alt text'))->info()->send()
                                : Notification::make()
                                    ->title(trans_choice('Writing alt text for :count image|Writing alt text for :count images', $count))
                                    ->body(__('It appears on each image as soon as the assistant is done.'))
                                    ->success()
                                    ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Tags for the selected images that have none, on the queue (ImageTags).
                    BulkAction::make('tagImages')
                        ->label(__('Tag with AI'))
                        ->icon('phosphor-tag')
                        ->visible(fn (): bool => WriteWithAi::isAvailable())
                        ->modalHeading(__('Tag with AI'))
                        ->modalDescription(__('The assistant tags the selected images that have no tags, reusing the library’s tags where they fit. Images that already have tags are left as they are.'))
                        ->modalIcon('phosphor-tag')
                        ->modalWidth('md')
                        ->modalSubmitActionLabel(__('Tag them'))
                        ->schema([
                            Textarea::make('instruction')
                                ->label(__('Instruction (optional)'))
                                ->placeholder(__('e.g. tag the people by department'))
                                ->rows(2)
                                ->maxLength(500),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = ImageTags::queueFor(
                                $records->filter(fn (Asset $asset): bool => auth()->user()->can('update', $asset)),
                                filled($data['instruction'] ?? null) ? $data['instruction'] : null,
                            );

                            $count === 0
                                ? Notification::make()->title(__('All the selected images already have tags'))->info()->send()
                                : Notification::make()
                                    ->title(trans_choice('Tagging :count image|Tagging :count images', $count))
                                    ->body(__('The tags appear on each image as soon as the assistant is done.'))
                                    ->success()
                                    ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->modalDescription(__('Files that are in use leave an empty space on those pages. Check "Used on" first.')),
                    // Last, set apart.
                    ActionGroup::make([AccessAction::bulk()])->dropdown(false),
                ]),
            ])
            // Folders drawn among the files are what's in it: no "no files here" under them.
            ->emptyState(fn ($livewire): ?HtmlString => $livewire instanceof ListAssets && $livewire->showsFolderItems() ? new HtmlString('') : null)
            ->emptyStateIcon('phosphor-images')
            ->emptyStateHeading(fn ($livewire): string => match (true) {
                $livewire instanceof ListAssets && $livewire->isLookingEverywhere() => __('No files match your search'),
                $livewire instanceof ListAssets && $livewire->subfolders()->isNotEmpty() => __('No files directly in this folder'),
                $livewire instanceof ListAssets && $livewire->currentFolder() !== null => __('This folder is empty'),
                default => __('The media library is empty'),
            })
            ->emptyStateDescription(fn ($livewire): string => $livewire instanceof ListAssets && $livewire->subfolders()->isNotEmpty() && ! $livewire->isLookingEverywhere()
                ? __('Open a folder above, or upload files into this one.')
                : __('Upload images and other files here, or images straight from an image field in the visual editor.'));
    }

    /**
     * The media library as rows instead of cards: the same records, filters and actions. A list
     * without a header row, its columns a set width, so the open folder's folders can be drawn
     * as rows above the files that line up with them (aurora::filament.assets.folder-items).
     * Sorting is in the toolbar (aurora::filament.assets.table-toolbar).
     */
    public static function listLayout(Table $table): Table
    {
        return $table
            ->contentGrid(null)
            ->columns([
                Split::make([
                    ViewColumn::make('thumbnail')
                        ->label('')
                        ->view('aurora::filament.assets.thumbnail')
                        ->viewData(['compact' => true])
                        ->grow(false),
                    TextColumn::make('title')
                        // A lock when the file has its own access.
                        ->icon(fn (Asset $record): ?string => $record->access === AssetAccess::CUSTOM ? 'phosphor-lock-key-fill' : null)
                        ->iconPosition('after')
                        ->iconColor('gray')
                        ->weight(FontWeight::SemiBold)
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->search($search))
                        ->sortable()
                        ->description(fn (Asset $record): string => $record->isImage() ? ($record->alt ?: __('Missing alt text')) : $record->type()->getLabel())
                        ->limit(50)
                        ->wrap()
                        ->extraAttributes(['class' => '[overflow-wrap:anywhere]']),
                    TextColumn::make('folder')
                        ->state(fn (Asset $record): ?string => $record->folder?->path())
                        ->placeholder(__('Top level'))
                        ->color('gray')
                        ->limit(30)
                        ->grow(false)
                        ->extraAttributes(['class' => 'cms-media-list-folder'])
                        ->visible(fn ($livewire): bool => $livewire instanceof ListAssets && $livewire->isLookingEverywhere()),
                    ViewColumn::make('tags')
                        ->view('aurora::filament.assets.tag-dots')
                        ->viewData(['list' => true])
                        ->grow(false),
                    TextColumn::make('size')
                        ->state(fn (Asset $record): ?string => $record->file() ? Number::fileSize($record->file()->size, 1) : null)
                        ->color('gray')
                        ->grow(false)
                        ->alignEnd()
                        ->extraAttributes(['class' => 'cms-media-list-size']),
                    TextColumn::make('created_at')
                        ->label(__('Uploaded'))
                        ->since()
                        ->dateTimeTooltip()
                        ->sortable()
                        ->color('gray')
                        ->grow(false)
                        ->alignEnd()
                        ->extraAttributes(['class' => 'cms-media-list-date']),
                ]),
            ]);
    }

    /**
     * Pick existing tags or create one on the spot; a new tag starts with a random colour.
     */
    public static function tagsSelect(): Select
    {
        return Select::make('tags')
            ->relationship('tags', 'name')
            ->getOptionLabelFromRecordUsing(fn (AssetTag $tag): string => static::tagLabel($tag))
            ->allowHtml()
            ->multiple()
            ->preload()
            ->searchable()
            ->placeholder(__('Add tags'))
            ->createOptionForm([
                TextInput::make('name')
                    ->required()
                    ->maxLength(50)
                    ->unique(AssetTag::class, 'name'),
                ColorPicker::make('color')
                    ->required()
                    ->default(fn (): string => AssetTag::randomColor())
                    ->helperText(__('Picked at random; change it if you like.')),
            ])
            ->createOptionModalHeading(__('New tag'));
    }

    /**
     * A tag as a coloured dot and its name, for select options.
     */
    public static function tagLabel(AssetTag $tag): string
    {
        return '<span class="inline-flex items-center gap-2"><span class="size-2.5 shrink-0 rounded-full" style="background-color: '.e($tag->color).'"></span>'.e($tag->name).'</span>';
    }

    public static function getRelations(): array
    {
        return [
            UsagesRelationManager::class,
            VariantsRelationManager::class,
        ];
    }

    /**
     * Where a file lives: Library › its folders (the page's title names the file). Each
     * folder opens that folder in the library.
     *
     * @return array<string, string>
     */
    public static function breadcrumbsFor(Asset $asset): array
    {
        $breadcrumbs = [static::getUrl('index') => __('Library')];

        foreach ($asset->folder?->trail() ?? [] as $folder) {
            $breadcrumbs[static::getUrl('index', ['folder' => $folder->id])] = $folder->name;
        }

        return $breadcrumbs;
    }

    /**
     * The original file, saved under its own name rather than opened in a tab.
     *
     * @param  Closure(): Asset  $asset
     */
    public static function downloadAction(Closure $asset): Action
    {
        return Action::make('download')
            ->label(__('Download'))
            ->icon('phosphor-download-simple')
            ->color('gray')
            ->visible(fn (): bool => $asset()->file() !== null)
            ->action(fn (): StreamedResponse => Storage::disk($asset()->file()->disk)->download($asset()->file()->getPathRelativeToRoot(), $asset()->file()->file_name));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'view' => ViewAsset::route('/{record}'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        // The file's own name too, as uploaded.
        return ['title', 'alt', 'media.name', 'media.file_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Asset $record */
        return $record->isImage()
            ? SearchThumbnail::title($record->title, $record->url('thumb'))
            : SearchThumbnail::title($record->title, null, icon: $record->type()->getIcon());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Asset $record */
        return array_filter([
            __('Folder') => $record->folder?->path() ?? __('Top level'),
            ...$record->isImage()
                ? [__('Alt text') => $record->alt ? str($record->alt)->limit(50)->toString() : __('Missing')]
                : [__('Type') => $record->type()->getLabel()],
        ]);
    }

    /**
     * A file's page for this person: to edit it, or to look at it when they may only read it.
     */
    public static function urlFor(Asset $record): ?string
    {
        return match (true) {
            static::canEdit($record) => static::getUrl('edit', ['record' => $record]),
            static::canView($record) => static::getUrl('view', ['record' => $record]),
            default => null,
        };
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return $record instanceof Asset ? static::urlFor($record) : null;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->tap(fn (Builder $query) => AssetAccess::scopeReadable($query, auth()->user()))->with(['media', 'folder']);
    }
}
