@php
    /** @var \Aurora\Filament\Resources\Assets\Pages\ListAssets $page */
    $table = $page->getTable();
    $contentGrid = $table->getContentGrid();
    // File rows start with their checkbox; a folder row keeps the same space, so they line up.
    $selectable = $table->isSelectionEnabled();
    $canManageFolders = auth()->user()->can('update Asset');
@endphp

{{-- The open folder's folders as the first cards of the grid, or the first rows of the list
     (ListAssets::folderItems(), drawn by the table before its records). They use the table's
     own record classes, so the themes draw them as they draw files. Opened with a click; files
     and folders are dropped on them (resources/js/admin/media-drag.js). --}}
{{-- Followed by files, the two meet without a gap or a line (.cms-folder-items in
     components.css); with none, the folders end the table as its records would, with its
     usual space and corners. --}}
<div @class(['fi-ta-content-ctn', 'cms-folder-items' => $page->getTableRecords()->isNotEmpty()]) data-cms-folder-items>
    <div
        aria-label="{{ __('Folders') }}"
        {{
            (new \Filament\Support\View\ComponentAttributeBag)
                ->when($contentGrid, fn ($attributes) => $attributes->grid($contentGrid))
                ->class(['fi-ta-content', 'fi-ta-content-grid' => $contentGrid])
        }}
    >
        @foreach ($page->subfolders() as $folder)
            @php
                $folderActions = implode(',', array_filter(['open', \Aurora\Support\AssetAccess::canWrite(auth()->user(), $folder) ? 'renameFolder,moveFolder,deleteFolder' : null, auth()->user()->can('manage-access Asset') && \Aurora\Support\AssetAccess::canWrite(auth()->user(), $folder) ? 'folderAccess' : null]));
                $total = \Aurora\Models\AssetFolder::assetTotals()[$folder->id] ?? 0;
                $count = trans_choice(':count image|:count images', $total).($folder->children_count ? ' · '.trans_choice(':count folder|:count folders', $folder->children_count) : '');
            @endphp
            <div
                role="button"
                tabindex="0"
                aria-label="{{ __('Open :folder', ['folder' => $folder->name]) }}"
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
                x-bind:class="{ 'cms-folder-record-over': over === @js($folder->id), 'cms-folder-record-target': over !== @js($folder->id) && dragging && dragging.folder !== @js($folder->id) }"
                @class([
                    'fi-ta-record fi-clickable cms-folder-record group',
                    'fi-ta-record-with-content-prefix' => $selectable,
                ])
            >
                @if ($selectable && ! $contentGrid)
                    {{-- Not .fi-ta-record-checkbox: the table finds its records by that class. --}}
                    <span class="cms-folder-record-spacer" aria-hidden="true"></span>
                @endif
                <div class="fi-ta-record-content-ctn">
                    <div>
                        <div class="fi-ta-record-content">
                            <div class="fi-grid-col fi-growable">
                                @if ($contentGrid)
                                    <div class="fi-ta-stack fi-align-start fi-gap-md">
                                        <div class="fi-grid-col fi-growable fi-ta-col">
                                            <div class="cms-file-tile cms-folder-record-tile grid h-36 w-full place-items-center rounded-lg text-primary-600 dark:text-primary-400">
                                                {{-- Opens up while something is dragged over it: drop here. --}}
                                                <x-filament::icon icon="phosphor-folder-fill" class="size-12" x-show="over !== {{ Js::from($folder->id) }}" />
                                                <x-filament::icon icon="phosphor-folder-open-fill" class="size-12" x-show="over === {{ Js::from($folder->id) }}" x-cloak />
                                            </div>
                                        </div>
                                        <div class="fi-grid-col fi-growable fi-ta-col">
                                            <div class="fi-ta-text-item fi-size-sm fi-font-semibold fi-ta-text fi-inline flex min-w-0 items-center gap-1.5">
                                                <span class="truncate">{{ $folder->name }}</span>
                                                @if ($folder->access === \Aurora\Support\AssetAccess::CUSTOM)
                                                    <x-filament::icon icon="phosphor-lock-key-fill" class="cms-access-lock size-4 shrink-0 text-gray-400" title="{{ __('Has its own access') }}" />
                                                @endif
                                            </div>
                                        </div>
                                        <div class="fi-grid-col fi-growable fi-ta-col">
                                            <div class="fi-ta-text-item fi-size-xs fi-color fi-color-gray fi-text-color-500 dark:fi-text-color-400 fi-ta-text fi-inline">{{ $count }}</div>
                                        </div>
                                    </div>
                                @else
                                    <div class="fi-ta-split default:fi-ta-split">
                                        <div class="fi-grid-col fi-ta-col">
                                            <div class="cms-file-tile cms-folder-record-tile grid size-12 shrink-0 place-items-center rounded-md text-primary-600 dark:text-primary-400">
                                                <x-filament::icon icon="phosphor-folder-fill" class="size-6" x-show="over !== {{ Js::from($folder->id) }}" />
                                                <x-filament::icon icon="phosphor-folder-open-fill" class="size-6" x-show="over === {{ Js::from($folder->id) }}" x-cloak />
                                            </div>
                                        </div>
                                        <div class="fi-grid-col fi-growable fi-ta-col">
                                            <div class="fi-ta-text fi-inline fi-wrapped fi-ta-text-has-descriptions">
                                                <p class="fi-ta-text-item fi-size-sm fi-font-semibold flex items-center gap-1.5">
                                                    {{ $folder->name }}
                                                    @if ($folder->access === \Aurora\Support\AssetAccess::CUSTOM)
                                                        <x-filament::icon icon="phosphor-lock-key-fill" class="cms-access-lock size-4 shrink-0 text-gray-400" title="{{ __('Has its own access') }}" />
                                                    @endif
                                                </p>
                                                <p class="fi-ta-text-description">{{ $count }}</p>
                                            </div>
                                        </div>
                                        {{-- The tags, size and date columns' places, empty, so the rows line up. --}}
                                        <div class="fi-grid-col fi-ta-col"><div class="cms-media-list-tags"></div></div>
                                        <div class="fi-grid-col fi-ta-col fi-align-end"><div class="cms-media-list-size"></div></div>
                                        <div class="fi-grid-col fi-ta-col fi-align-end"><div class="cms-media-list-date"></div></div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
