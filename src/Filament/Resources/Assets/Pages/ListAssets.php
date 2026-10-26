<?php

namespace Aurora\Filament\Resources\Assets\Pages;

use Aurora\Enums\AssetType;
use Aurora\Filament\Actions\AccessAction;
use Aurora\Filament\Concerns\ManagesImageFormats;
use Aurora\Filament\Forms\Components\FolderSelect;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\Setting;
use Aurora\Support\AssetAccess;
use Aurora\Support\AssetLibrary;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Number;
use Illuminate\Validation\Rules\Unique;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

/**
 * The media library, browsed like a file manager: breadcrumbs, the folders in the open
 * folder, then its images. A search looks in every folder.
 */
class ListAssets extends ListRecords
{
    use ManagesImageFormats;

    protected static string $resource = AssetResource::class;

    protected static ?string $title = 'Media library';

    /**
     * Whether folders are drawn among the files, as their grid's first cards (on by default), or
     * in a grid of their own above them (Settings › Media › Media library).
     */
    public const FOLDERS_INLINE_SETTING = 'media_folders_inline';

    /**
     * The open folder (null = top level). Kept in the URL so folders can be bookmarked, one
     * history entry per folder so the browser's back button returns to the folder before.
     * Going back sets it from the browser: updatedFolder() checks it as openFolder() does.
     */
    #[Url(history: true)]
    public ?string $folder = null;

    /**
     * "grid" (cards) or "list" (rows). Kept in the URL and remembered for the session.
     */
    #[Url(as: 'view', except: 'grid')]
    public string $display = 'grid';

    /**
     * Files dragged in from the desktop, one slot per file so each uploads (and reports
     * progress) on its own. Each is stored by storeDroppedUpload() once it arrives.
     *
     * @var array<string, TemporaryUploadedFile>
     */
    public array $dropped = [];

    /**
     * Its folders drawn among the files, and the select-all checkbox in the toolbar: render
     * hooks of its table, for this page only. Registered once, by the first one drawn.
     */
    public function boot(): void
    {
        $hooks = [
            TablesRenderHook::CONTENT_BEFORE => fn (): string => ($page = Livewire::current()) instanceof self ? $page->folderItems() : '',
            TablesRenderHook::TOOLBAR_REORDER_TRIGGER_BEFORE => fn (): string => ($page = Livewire::current()) instanceof self ? $page->tableToolbar() : '',
        ];

        foreach ($hooks as $name => $hook) {
            if (! FilamentView::hasRenderHook($name, static::class)) {
                FilamentView::registerRenderHook($name, $hook, static::class);
            }
        }
    }

    public function mount(): void
    {
        parent::mount();

        if (! request()->has('view')) {
            $this->display = session('media.display', 'grid');
        }

        $this->display = in_array($this->display, ['grid', 'list'], true) ? $this->display : 'grid';
    }

    public function table(Table $table): Table
    {
        return $this->display === 'list' ? AssetResource::listLayout($table) : $table;
    }

    /**
     * The table's columns differ per layout, so switching reloads the page.
     */
    public function switchDisplay(string $display): void
    {
        $display = $display === 'list' ? 'list' : 'grid';

        session(['media.display' => $display]);

        $this->redirect(static::getUrl(array_filter(['folder' => $this->folder, 'view' => $display === 'list' ? 'list' : null])), navigate: true);
    }

    /**
     * The folder trail above the grid replaces Filament's breadcrumbs.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->currentFolder()?->name ?? __('Media library');
    }

    /**
     * Inside a folder, the heading starts with a back arrow that opens the folder above it.
     */
    public function getHeading(): string|Htmlable|null
    {
        if ($this->currentFolder() === null) {
            return $this->getTitle();
        }

        return new HtmlString(view('aurora::filament.assets.heading', [
            'folder' => $this->currentFolder(),
            'parent' => $this->currentFolder()->parent_id ? $this->folderById($this->currentFolder()->parent_id) : null,
        ])->render());
    }

    /**
     * Per-request lookups, keyed by folder so opening another folder never reads a stale one.
     *
     * @var array<string, mixed>
     */
    protected array $folderCache = [];

    /**
     * The folder a folder action is for: one picked from a tile's menu (the "folder" argument),
     * else the open folder. Without arguments, the mounted action's.
     *
     * @param  array<string, mixed>|null  $arguments
     */
    protected function targetFolder(?array $arguments = null): ?AssetFolder
    {
        $arguments ??= $this->mountedActions[array_key_last($this->mountedActions ?? []) ?? 0]['arguments'] ?? [];

        if (! isset($arguments['folder'])) {
            return $this->currentFolder();
        }

        return $this->folderCache['folder:'.$arguments['folder']] ??= AssetFolder::query()->find($arguments['folder']);
    }

    public function currentFolder(): ?AssetFolder
    {
        if ($this->folder === null) {
            return null;
        }

        return $this->allFolders()->get($this->folder);
    }

    /**
     * Every folder, by id, in one query: the tree and the trail read it instead of asking for
     * each parent in turn.
     *
     * @return Collection<string, AssetFolder>
     */
    protected function allFolders(): Collection
    {
        // Only the folders this person may read (AssetAccess).
        return $this->folderCache['all'] ??= AssetFolder::query()
            ->unless(auth()->user()->isSuperAdmin(), fn ($query) => $query->whereKey(AssetAccess::readableFolderIds(auth()->user())))
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * A folder this person may see, by id (from the folders already loaded).
     */
    public function folderById(string $id): ?AssetFolder
    {
        return $this->allFolders()->get($id);
    }

    /**
     * The open folder and the ones above it, top first (empty at the top level).
     *
     * @return Collection<int, AssetFolder>
     */
    public function currentTrail(): Collection
    {
        $folders = $this->allFolders();
        $trail = collect();

        for ($folder = $folders->get((string) $this->folder); $folder !== null; $folder = $folders->get((string) $folder->parent_id)) {
            $trail->prepend($folder);
        }

        return $trail;
    }

    /**
     * @return Collection<int, AssetFolder>
     */
    public function subfolders(): Collection
    {
        return $this->folderCache['children:'.($this->folder ?? 'root')] ??= AssetFolder::query()
            ->where('parent_id', $this->currentFolder()?->id)
            ->unless(auth()->user()->isSuperAdmin(), fn ($query) => $query->whereKey(AssetAccess::readableFolderIds(auth()->user())))
            ->withCount(['assets', 'children'])
            ->orderBy('name')
            ->get();
    }

    /**
     * A search or a tag filter spans every folder rather than just the open one.
     */
    public function isLookingEverywhere(): bool
    {
        return filled($this->getTableSearch()) || filled($this->getTableFilterState('tags')['values'] ?? null);
    }

    /**
     * The open folder's folders, drawn by the table before its files so they read as the first
     * cards of the grid (or rows of the list). Only on the first page, and not while a search or
     * tag filter looks in every folder. Registered as a table render hook (AdminPanelProvider).
     */
    public function folderItems(): string
    {
        return $this->showsFolderItems() ? view('aurora::filament.assets.folder-items', ['page' => $this])->render() : '';
    }

    /**
     * Whether folders are drawn among the files on this page. Then, with no files, the table's
     * "no files" message is left out too: the folders are there to open.
     */
    public function showsFolderItems(): bool
    {
        $records = $this->getTableRecords();

        return $this->showsFoldersInline()
            && ! $this->isLookingEverywhere()
            && $this->subfolders()->isNotEmpty()
            && ! (method_exists($records, 'currentPage') && $records->currentPage() > 1);
    }

    public function showsFoldersInline(): bool
    {
        return Setting::get(self::FOLDERS_INLINE_SETTING) !== false;
    }

    /**
     * The select-all checkbox, and in the list the sort, at the start of the table's toolbar:
     * with the folders inline, where the table's own bar for them would come between the
     * folders and the files.
     */
    public function tableToolbar(): string
    {
        if (! $this->showsFoldersInline()) {
            return '';
        }

        return view('aurora::filament.assets.table-toolbar', ['page' => $this])->render();
    }

    public function openFolder(?string $folder): void
    {
        $this->folder = $folder !== null && $this->allFolders()->has($folder) ? $folder : null;
        $this->resetTable();
    }

    /**
     * The folder set by the browser's back and forward buttons: one this person may see, or the top level.
     */
    public function updatedFolder(?string $folder): void
    {
        $this->openFolder($folder);
    }

    /**
     * Images dropped onto a folder tile or breadcrumb (null = the top level).
     *
     * Either a list of ids (the dragged image, or the selection it belongs to) or, after
     * "select all", every image the table shows except those deselected.
     *
     * @param  array<int, string>  $ids
     * @param  array<int, string>  $except
     */
    public function moveAssetsToFolder(array $ids, ?string $folderId, bool $all = false, array $except = []): void
    {
        $folder = $folderId === null ? null : AssetFolder::query()->find($folderId);

        if (($folderId !== null && $folder === null) || ! AssetAccess::canWrite(auth()->user(), $folder)) {
            return;
        }

        // "Select all" covers what the table shows: the open folder, or the search/tag results.
        $assets = ($all ? $this->getFilteredTableQuery()->whereNotIn('assets.id', $except) : Asset::query()->whereIn('id', $ids))
            ->get()
            ->filter(fn (Asset $asset): bool => auth()->user()->can('update', $asset) && $asset->folder_id !== $folder?->id);

        if ($assets->isEmpty()) {
            return;
        }

        Asset::query()->whereIn('id', $assets->modelKeys())->update(['folder_id' => $folder?->id]);

        $this->folderCache = [];
        $this->deselectAllTableRecords();

        Notification::make()
            ->title(trans_choice('Moved :count image to :folder|Moved :count images to :folder', $assets->count(), ['folder' => $folder?->name ?? __('Library')]))
            ->success()
            ->send();
    }

    /**
     * Store one file dragged in from the desktop in the given folder (null = the top level).
     *
     * @return array{id?: string, title?: string, error?: string}
     */
    public function storeDroppedUpload(string $key, ?string $folderId): array
    {
        $file = $this->dropped[$key] ?? null;
        unset($this->dropped[$key]);

        if (! auth()->user()->can('create', Asset::class)) {
            return ['error' => __('You can’t upload files.')];
        }

        if (! $file instanceof UploadedFile) {
            return ['error' => __('The upload didn’t arrive.')];
        }

        if ($rejection = AssetLibrary::rejectionFor($file)) {
            return ['error' => $rejection];
        }

        $folder = $folderId !== null ? AssetFolder::query()->find($folderId) : null;

        if (! AssetAccess::canWrite(auth()->user(), $folder)) {
            return ['error' => __('You can’t add files to this folder.')];
        }

        $asset = AssetLibrary::fromUpload($file, ['folder_id' => $folder?->id]);

        $this->folderCache = [];

        return ['id' => $asset->id, 'title' => $asset->title];
    }

    /**
     * A folder tile dropped onto another folder or a breadcrumb (null = the top level).
     * A folder can't go inside itself or its own subfolders, nor next to a folder of the same name.
     */
    public function moveFolderToFolder(string $folderId, ?string $targetId): void
    {
        if (! auth()->user()->can('update Asset')) {
            return;
        }

        $folder = AssetFolder::query()->find($folderId);
        $target = $targetId === null ? null : AssetFolder::query()->find($targetId);

        if ($folder === null || ($targetId !== null && $target === null) || $folder->parent_id === $target?->id) {
            return;
        }

        if (! AssetAccess::canWrite(auth()->user(), $folder) || ! AssetAccess::canWrite(auth()->user(), $target)) {
            Notification::make()->title(__('You can’t change these folders'))->warning()->send();

            return;
        }

        if ($target !== null && in_array($target->id, $folder->descendantIds(), true)) {
            Notification::make()->title(__('A folder can’t be moved into itself'))->warning()->send();

            return;
        }

        $nameTaken = AssetFolder::query()
            ->where('parent_id', $target?->id)
            ->where('name', $folder->name)
            ->exists();

        if ($nameTaken) {
            Notification::make()
                ->title(__(':target already has a folder called :name', ['target' => $target?->name ?? __('Library'), 'name' => $folder->name]))
                ->warning()
                ->send();

            return;
        }

        $folder->update(['parent_id' => $target?->id]);

        $this->folderCache = [];

        Notification::make()
            ->title(__('Moved :name to :target', ['name' => $folder->name, 'target' => $target?->name ?? __('Library')]))
            ->success()
            ->send();
    }

    /**
     * The folders and files. Dragging (images and folders onto folders, files from the desktop)
     * lives on the layout around them, so the folder tree in the "Library" breadcrumb's
     * dropdown takes drops too.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // A folder they may look in but not change: said before they try.
            Callout::make(__('You can view this folder, but not change it'))
                ->description(__('Adding, moving and deleting files and folders here needs someone with write access.'))
                ->icon('phosphor-eye')
                ->info()
                ->visible(fn (): bool => $this->currentFolder() !== null && ! AssetAccess::canWrite(auth()->user(), $this->currentFolder())),
            Group::make([
                View::make('aurora::filament.assets.folder-browser'),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                // Opening a folder: its files fade while they load (the folder tree answers at once).
                Group::make([EmbeddedTable::make()])->extraAttributes([
                    'class' => 'transition-opacity',
                    'wire:loading.delay.class' => 'opacity-50 pointer-events-none',
                    'wire:target' => 'openFolder',
                ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ])->extraAttributes(fn (): array => $this->dragAttributes()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function dragAttributes(): array
    {
        $size = fn (int $kilobytes): string => Number::fileSize($kilobytes * 1024);

        return [
            'class' => 'cms-media-layout'.($this->showsFoldersInline() ? ' cms-media-folders-inline' : ''),
            'x-data' => 'cmsMediaDrag('.Js::from([
                'canUpload' => auth()->user()->can('create', Asset::class) && AssetAccess::canWrite(auth()->user(), $this->currentFolder()),
                'strings' => [
                    'drop_to' => __('Drop to upload to :folder'),
                    'files_one' => __(':count file'),
                    'files_many' => __(':count files'),
                    'too_large_image' => __('Larger than :size.', ['size' => $size(AssetType::maxImageKilobytes())]),
                    'too_large_file' => __('Larger than :size.', ['size' => $size(AssetType::maxFileKilobytes())]),
                    'uploading' => __('Uploading :current of :total'),
                    'uploaded_failed' => __(':done uploaded, :failed failed'),
                    'uploaded_one' => __(':count file uploaded'),
                    'uploaded_many' => __(':count files uploaded'),
                    'failed' => __('The upload failed.'),
                    'queued' => __('Waiting'),
                    'saving' => __('Processing…'),
                    'done' => __('Done'),
                    'failed_status' => __('Failed'),
                ],
            ]).')',
            'data-folder-name' => $this->currentFolder()?->name ?? __('Library'),
            'x-on:pointerdown.window' => 'pointerDown($event)',
            'x-on:dragenter.window' => 'fileEnter($event)',
            'x-on:dragleave.window' => 'fileLeave($event)',
            'x-on:dragover.window' => 'fileOver($event)',
            'x-on:drop.window' => 'fileDrop($event)',
        ];
    }

    /**
     * Every folder in tree order, for the folder tree: each with its depth and the folders
     * above it (it shows while they are all opened), and the folders opened to begin with:
     * the open folder and the ones above it.
     *
     * @return array{rows: array<int, array{folder: AssetFolder, depth: int, ancestors: array<int, string>, hasChildren: bool}>, expanded: array<int, string>}
     */
    public function folderTree(?string $under = null): array
    {
        $children = $this->allFolders()->values()->groupBy(fn (AssetFolder $folder): string => (string) $folder->parent_id);
        $rows = [];

        $walk = function (string $parent, array $ancestors) use (&$walk, &$rows, $children): void {
            foreach ($children->get($parent, collect()) as $folder) {
                $rows[] = ['folder' => $folder, 'depth' => count($ancestors), 'ancestors' => $ancestors, 'hasChildren' => $children->has($folder->id)];
                $walk($folder->id, [...$ancestors, $folder->id]);
            }
        };

        $walk((string) $under, []);

        return [
            'rows' => $rows,
            'expanded' => $this->currentTrail()->pluck('id')->all(),
        ];
    }

    protected function getHeaderActions(): array
    {
        // Managing a folder needs write access to it too (AssetAccess).
        $canManage = fn (array $arguments = []): bool => auth()->user()->can('update Asset') && AssetAccess::canWrite(auth()->user(), $this->targetFolder($arguments));
        $canAddHere = fn (): bool => AssetAccess::canWrite(auth()->user(), $this->currentFolder());

        return [
            // The open folder's actions, under "More". Right-clicking empty space among the files
            // opens the same menu (resources/js/admin/context-menu.js), and a folder tile's
            // right-click menu mounts these actions with that folder as the "folder" argument.
            ActionGroup::make([
                Action::make('newFolder')
                    ->label(__('New folder'))
                    ->icon('phosphor-folder-plus')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()->can('create', Asset::class) && $canAddHere())
                    ->modalHeading(fn (): string => $this->currentFolder() ? __('New folder in “:name”', ['name' => $this->currentFolder()->name]) : __('New folder'))
                    ->modalWidth('md')
                    ->schema([$this->folderNameInput(fn (): ?string => $this->folder)])
                    ->action(function (array $data): void {
                        AssetFolder::query()->create(['name' => $data['name'], 'parent_id' => $this->folder]);
                        $this->folderCache = [];
                    }),
                Action::make('renameFolder')
                    ->label(__('Rename folder'))
                    ->icon('phosphor-note-pencil')
                    ->visible(fn (array $arguments): bool => $this->targetFolder($arguments) !== null && $canManage($arguments))
                    ->modalHeading(fn (array $arguments): string => __('Rename “:name”', ['name' => $this->targetFolder($arguments)?->name]))
                    ->modalSubmitActionLabel(__('Rename'))
                    ->modalWidth('md')
                    ->fillForm(fn (array $arguments): array => ['name' => $this->targetFolder($arguments)?->name])
                    ->schema([$this->folderNameInput(fn (): ?string => $this->targetFolder()?->parent_id, ignore: fn (): ?string => $this->targetFolder()?->id)])
                    ->action(function (array $data, array $arguments): void {
                        $this->targetFolder($arguments)?->update(['name' => $data['name']]);
                        $this->folderCache = [];
                    }),
                Action::make('moveFolder')
                    ->label(__('Move folder'))
                    ->icon('phosphor-folder-simple')
                    ->modalWidth('md')
                    ->visible(fn (array $arguments): bool => $this->targetFolder($arguments) !== null && $canManage($arguments))
                    ->modalSubmitActionLabel(__('Move'))
                    ->fillForm(fn (array $arguments): array => ['parent_id' => $this->targetFolder($arguments)?->parent_id])
                    ->schema([
                        FolderSelect::make('parent_id')
                            ->label(__('Move into'))
                            ->unavailable(fn (): array => $this->targetFolder()?->descendantIds() ?? []),
                    ])
                    ->action(function (array $data, array $arguments, Action $action): void {
                        $folder = $this->targetFolder($arguments);
                        $target = $data['parent_id'] ?? null;

                        if (! AssetAccess::canWrite(auth()->user(), $target ? AssetFolder::query()->find($target) : null)) {
                            Notification::make()->title(__('You can’t add folders there'))->warning()->send();
                            $action->halt();
                        }

                        if ($folder !== null && AssetFolder::nameTakenIn($target, $folder->name, $folder->getKey())) {
                            Notification::make()
                                ->title(__(':target already has a folder called :name', ['target' => AssetFolder::query()->find($target)?->name ?? __('Library'), 'name' => $folder->name]))
                                ->warning()
                                ->send();
                            $action->halt();
                        }

                        $folder?->update(['parent_id' => $target]);
                        $this->folderCache = [];
                    }),
                Action::make('deleteFolder')
                    ->label(__('Delete folder'))
                    ->icon('phosphor-trash')
                    ->color('danger')
                    ->visible(fn (array $arguments): bool => $this->targetFolder($arguments) !== null && $canManage($arguments))
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $arguments): string => __('Delete “:name”?', ['name' => $this->targetFolder($arguments)?->name]))
                    ->modalDescription(__('The images and folders inside are not deleted: they move up one level.'))
                    ->action(function (array $arguments, Action $action): void {
                        $folder = $this->targetFolder($arguments);

                        // Its subfolders move up a level: not next to a folder with the same name.
                        if ($folder !== null && ($clashes = $folder->clashesOnDelete()) !== []) {
                            Notification::make()
                                ->title(__('“:name” can’t be deleted yet', ['name' => $folder->name]))
                                ->body(__('Its folder :folders would move next to one with the same name. Rename or move it first.', ['folders' => '“'.implode('”, “', $clashes).'”']))
                                ->warning()
                                ->send();
                            $action->halt();
                        }

                        $wasOpen = $folder?->id === $this->folder;
                        $folder?->delete();
                        $this->folderCache = [];

                        if ($wasOpen) {
                            $this->openFolder($folder->parent_id);
                        }
                    }),
                // Not the open folder's: for the whole library, set apart below them.
                ActionGroup::make([$this->formatsAction()])->dropdown(false),
                // Who can see and change the folder and what's in it: last, set apart.
                ActionGroup::make([AccessAction::make('folderAccess', fn (array $arguments): ?AssetFolder => $this->targetFolder($arguments))])->dropdown(false),
            ])
                ->label(__('More'))
                ->icon('phosphor-dots-three-vertical')
                ->button()
                ->color('gray')
                ->extraDropdownAttributes(['data-cms-files-actions' => '']),
            Action::make('upload')
                ->label(__('Upload files'))
                ->icon('phosphor-upload-simple')
                ->visible(fn (): bool => auth()->user()->can('create', Asset::class) && $canAddHere())
                ->modalHeading(fn (): string => $this->currentFolder() ? __('Upload to “:name”', ['name' => $this->currentFolder()->name]) : __('Upload files'))
                ->modalSubmitActionLabel(__('Upload'))
                ->schema([
                    FileUpload::make('files')
                        ->hiddenLabel()
                        ->helperText(__('Images, video, audio, PDFs, Office documents, text and zip files. Images up to :image, other files up to :file.', ['image' => Number::fileSize(AssetType::maxImageKilobytes() * 1024), 'file' => Number::fileSize(AssetType::maxFileKilobytes() * 1024)]))
                        ->acceptedFileTypes(AssetType::allowedMimeTypes())
                        ->multiple()
                        ->storeFiles(false)
                        ->maxSize(AssetType::maxFileKilobytes())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $files = array_filter(Arr::wrap($data['files']), fn ($file) => $file instanceof TemporaryUploadedFile);
                    $uploaded = collect();
                    $rejected = [];

                    foreach ($files as $file) {
                        if ($rejection = AssetLibrary::rejectionFor($file)) {
                            $rejected[] = $file->getClientOriginalName().': '.$rejection;

                            continue;
                        }

                        $uploaded->push(AssetLibrary::fromUpload($file, ['folder_id' => $this->folder]));
                    }

                    if ($uploaded->isNotEmpty()) {
                        Notification::make()
                            ->title(trans_choice('Uploaded :count file|Uploaded :count files', $uploaded->count()))
                            ->body($uploaded->contains(fn (Asset $asset): bool => $asset->isImage()) ? __('Add alt text to the images so everyone can understand them.') : null)
                            ->success()
                            ->send();
                    }

                    if ($rejected !== []) {
                        Notification::make()
                            ->title(trans_choice(':count file wasn’t uploaded|:count files weren’t uploaded', count($rejected)))
                            ->body(implode("\n", $rejected))
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    protected function folderNameInput(\Closure $parent, ?\Closure $ignore = null): TextInput
    {
        return TextInput::make('name')
            ->label(__('Name'))
            ->required()
            ->maxLength(80)
            ->notRegex('#[/>]#')
            ->unique(
                table: AssetFolder::class,
                column: 'name',
                ignorable: fn (): ?AssetFolder => $ignore ? AssetFolder::query()->find($ignore()) : null,
                modifyRuleUsing: fn (Unique $rule): Unique => ($parent() === null ? $rule->whereNull('parent_id') : $rule->where('parent_id', $parent())),
            )
            ->validationMessages([
                'not_regex' => __('A folder name cannot contain / or >.'),
                'unique' => __('There is already a folder with that name here.'),
            ]);
    }
}
