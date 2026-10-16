@php
    /** @var \Aurora\Filament\Resources\Assets\Pages\ListAssets $page */
    $page = $this;
    $current = $page->currentFolder();
    $folders = $page->subfolders();
    $searching = $page->isLookingEverywhere();
    $canManageFolders = auth()->user()->can('update Asset');
    $canUpload = auth()->user()->can('create', \Aurora\Models\Asset::class);
@endphp

{{-- Drag and drop of images and folders (resources/js/admin/media-drag.js): its state is on the
     layout around this and the folder tree (ListAssets::content()), so both take drops. --}}
<div class="space-y-4">
    @if ($canUpload)
        {{-- Shown while image files from the desktop are dragged over the page. --}}
        <div
            x-show="isDraggingFiles"
            x-cloak
            x-transition.opacity
            class="pointer-events-none fixed inset-3 z-40 flex items-end justify-center rounded-2xl border-2 border-dashed border-primary-500 bg-primary-500/5 pb-10"
        >
            <span class="inline-flex items-center gap-2 rounded-full bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-lg">
                <x-filament::icon icon="phosphor-upload-simple-bold" class="size-4" />
                <span x-text="dropLabel"></span>
            </span>
        </div>

        {{-- One row per dropped file, with its own progress bar. --}}
        <div
            x-show="uploads.length"
            x-cloak
            x-transition
            wire:ignore
            class="cms-upload-panel fixed bottom-4 end-4 z-50 w-80 overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            role="status"
        >
            <div class="flex items-center justify-between gap-3 border-b border-gray-950/5 px-4 py-3 dark:border-white/10">
                <p class="text-sm font-semibold text-gray-950 dark:text-white"
                    x-text="uploadHeading"></p>
                <button type="button" x-show="! activeUploads.length" x-on:click="clearUploads()" class="text-gray-400 hover:text-gray-500" aria-label="{{ __('Close') }}">
                    <x-filament::icon icon="phosphor-x-bold" class="size-5" />
                </button>
            </div>
            <ul class="max-h-80 divide-y divide-gray-950/5 overflow-y-auto dark:divide-white/10">
                <template x-for="upload in uploads" x-bind:key="upload.key">
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <div class="size-10 shrink-0 overflow-hidden rounded-md bg-gray-100 dark:bg-white/5">
                            <img x-show="upload.preview" x-bind:src="upload.preview" alt="" class="size-full object-cover" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white" x-text="upload.name"></p>
                                <span class="shrink-0 text-xs tabular-nums"
                                    x-bind:class="upload.status === 'failed' ? 'text-danger-600 dark:text-danger-400' : (upload.status === 'done' ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400')"
                                    x-text="{ queued: t('queued'), uploading: upload.progress + '%', saving: t('saving'), done: t('done'), failed: t('failed_status') }[upload.status]"></span>
                            </div>
                            <div x-show="upload.status !== 'failed'" class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                <div class="h-full rounded-full transition-[width] duration-200"
                                    x-bind:class="upload.status === 'done' ? 'bg-success-500' : 'bg-primary-600'"
                                    x-bind:style="`width: ${upload.status === 'saving' || upload.status === 'done' ? 100 : upload.progress}%`"></div>
                            </div>
                            <p x-show="upload.error" class="mt-0.5 truncate text-xs text-danger-600 dark:text-danger-400" x-text="upload.error"></p>
                        </div>
                    </li>
                </template>
            </ul>
        </div>
    @endif

    {{-- A file card's right-click menu starts with this (resources/js/admin/context-menu.js). --}}
    <template data-cms-file-menu>
        <x-filament::dropdown.list.item data-file-action="open" icon="phosphor-arrow-square-up-right">{{ __('Open') }}</x-filament::dropdown.list.item>
    </template>

    {{-- A folder tile's right-click menu (resources/js/admin/context-menu.js). --}}
    @if ($canManageFolders)
        <template data-cms-folder-menu>
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item data-folder-action="open" icon="phosphor-folder-open">{{ __('Open') }}</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item data-folder-action="renameFolder" icon="phosphor-note-pencil">{{ __('Rename') }}</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item data-folder-action="moveFolder" icon="phosphor-folder-simple">{{ __('Move') }}</x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item data-folder-action="deleteFolder" icon="phosphor-trash" color="danger">{{ __('Delete') }}</x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
            {{-- Last, in a section of its own. --}}
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item data-folder-action="folderAccess" icon="phosphor-lock-key">{{ __('Access') }}</x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </template>
    @endif

    <nav aria-label="{{ __('Folders') }}" class="cms-folder-trail flex flex-wrap items-center gap-1 text-sm">
        {{-- "Library" opens every folder as a tree (folder-tree.blade.php); still a drop target. --}}
        <x-filament::dropdown placement="bottom-start" max-height="26rem" class="cms-folder-tree-dropdown" wire:key="folder-tree-dropdown">
            <x-slot name="trigger">
                <button type="button" data-drop-name="{{ __('Library') }}"
                    data-drop-folder=""
                    aria-label="{{ __('Library: choose a folder') }}"
                    x-on:dragover="enter($event, null)" x-on:dragleave="leave($event, null)"
                    x-bind:class="over === null && (dragging || isDraggingFiles) ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/10' : ''"
                    @class(['inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-medium transition hover:bg-gray-100 dark:hover:bg-white/5', 'text-gray-950 dark:text-white' => ! $current, 'text-gray-500 dark:text-gray-400' => $current])>
                    <x-filament::icon icon="phosphor-image-bold" class="size-4" /> {{ __('Library') }}
                    <x-filament::icon icon="phosphor-caret-down-bold" class="size-3.5 opacity-60" />
                </button>
            </x-slot>

            @include('aurora::filament.assets.folder-tree')
        </x-filament::dropdown>
        {{-- Each folder in the trail opens the tree of its parent: itself and the folders next to it. --}}
        @foreach ($page->currentTrail() as $crumb)
            <x-filament::icon icon="phosphor-caret-right-bold" class="size-4 text-gray-400" />
            <x-filament::dropdown placement="bottom-start" max-height="26rem" class="cms-folder-tree-dropdown" wire:key="folder-tree-dropdown-{{ $crumb->id }}">
                <x-slot name="trigger">
                    <button type="button" data-drop-name="{{ $crumb->name }}"
                        data-drop-folder="{{ $crumb->id }}"
                        aria-label="{{ __(':folder: choose a folder', ['folder' => $crumb->name]) }}"
                        x-on:dragover="enter($event, @js($crumb->id))" x-on:dragleave="leave($event, @js($crumb->id))"
                        x-bind:class="over === @js($crumb->id) ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/10' : ''"
                        @class(['inline-flex items-center gap-1.5 rounded-lg px-2 py-1 font-medium transition hover:bg-gray-100 dark:hover:bg-white/5', 'text-gray-950 dark:text-white' => $crumb->is($current), 'text-gray-500 dark:text-gray-400' => ! $crumb->is($current)])>
                        {{ $crumb->name }}
                        <x-filament::icon icon="phosphor-caret-down-bold" class="size-3.5 opacity-60" />
                    </button>
                </x-slot>

                @include('aurora::filament.assets.folder-tree', ['under' => $crumb->parent_id ? $page->folderById($crumb->parent_id) : null])
            </x-filament::dropdown>
        @endforeach
        @if ($searching)
            <span class="ms-2 rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ filled($page->getTableSearch()) ? __('Searching all folders') : __('Showing all folders') }}</span>
        @endif
        <div class="cms-segment ms-auto inline-flex rounded-lg p-0.5 ring-1 ring-gray-950/10 dark:ring-white/10" role="group" aria-label="{{ __('Layout') }}">
            @foreach (['grid' => ['phosphor-squares-four-bold', __('Grid')], 'list' => ['phosphor-list-bullets-bold', __('List')]] as $layout => [$icon, $label])
                <button
                    type="button"
                    wire:click="switchDisplay('{{ $layout }}')"
                    aria-pressed="{{ $page->display === $layout ? 'true' : 'false' }}"
                    x-tooltip="{ content: @js($label), theme: $store.theme }"
                    @class([
                        'grid size-8 place-items-center rounded-md transition',
                        'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $page->display === $layout,
                        'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200' => $page->display !== $layout,
                    ])
                >
                    <x-filament::icon :icon="$icon" class="size-5" />
                    <span class="sr-only">{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </nav>

    @if ($folders->isNotEmpty() && ! $searching)
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
            @foreach ($folders as $folder)
                @php($folderActions = implode(',', array_filter(['open', \Aurora\Support\AssetAccess::canWrite(auth()->user(), $folder) ? 'renameFolder,moveFolder,deleteFolder' : null, auth()->user()->can('manage-access Asset') && \Aurora\Support\AssetAccess::canWrite(auth()->user(), $folder) ? 'folderAccess' : null])))
                <div
                    role="button"
                    tabindex="0"
                    wire:click="openFolder(@js($folder->id))"
                    x-on:keydown.enter="$wire.openFolder(@js($folder->id))"
                    x-on:keydown.space.prevent="$wire.openFolder(@js($folder->id))"
                    wire:key="folder-{{ $folder->id }}"
                    data-drop-name="{{ $folder->name }}"
                    data-drop-folder="{{ $folder->id }}"
                    @if ($canManageFolders)
                        data-cms-folder="{{ $folder->id }}"
                        {{-- What its right-click menu offers: only what they may do with it. --}}
                        data-cms-folder-actions="{{ $folderActions }}"
                    @endif
                    x-on:dragover="enter($event, @js($folder->id))"
                    x-on:dragleave="leave($event, @js($folder->id))"
                    x-bind:class="over === @js($folder->id) ? 'ring-2! ring-primary-500! bg-primary-50! dark:bg-primary-500/10! scale-[1.02]' : (dragging && dragging.folder !== @js($folder->id) ? 'ring-primary-300 dark:ring-primary-500/40' : '')"
                    class="cms-folder-tile group flex cursor-pointer items-center gap-3 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-primary-500 bg-white p-3 text-start shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-500"
                >
                    <span class="cms-folder-icon grid size-10 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-600 transition group-hover:scale-105 dark:bg-primary-500/10 dark:text-primary-400">
                        {{-- Opens up while something is dragged over it: drop here. --}}
                        <x-filament::icon icon="phosphor-folder-fill" class="size-5" x-show="over !== {{ Js::from($folder->id) }}" />
                        <x-filament::icon icon="phosphor-folder-open-fill" class="size-5" x-show="over === {{ Js::from($folder->id) }}" x-cloak />
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-1.5 text-sm font-semibold text-gray-950 dark:text-white">
                            <span class="truncate">{{ $folder->name }}</span>
                            @if ($folder->access === \Aurora\Support\AssetAccess::CUSTOM)
                                <x-filament::icon icon="phosphor-lock-key-fill" class="cms-access-lock" title="{{ __('Has its own access') }}" />
                            @endif
                        </span>
                        <span class="block truncate text-xs text-gray-500">
                            @php($total = \Aurora\Models\AssetFolder::assetTotals()[$folder->id] ?? 0)
                            {{ trans_choice(':count image|:count images', $total) }}@if ($folder->children_count) · {{ trans_choice(':count folder|:count folders', $folder->children_count) }}@endif
                        </span>
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>
