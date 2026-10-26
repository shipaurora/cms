@php
    /** @var \Aurora\Filament\Resources\Assets\Pages\ListAssets $page */
    $page = $this;
    // The whole library, or (a breadcrumb's tree) what's under one folder: $under.
    $under ??= null;
    // The open folder's breadcrumb: only the folders next to it (its parent's own), as a flat list.
    $siblings ??= false;
    $scope = ($siblings ? 'siblings-' : '').($under?->id ?? 'root');
    ['rows' => $rows, 'expanded' => $expanded] = $page->folderTree($under?->id);

    if ($siblings) {
        $rows = array_values(array_map(fn (array $row): array => [...$row, 'hasChildren' => false], array_filter($rows, fn (array $row): bool => $row['depth'] === 0)));
    }
    $current = $page->currentFolder();
    $totals = \Aurora\Models\AssetFolder::assetTotals();
    $canManageFolders = auth()->user()->can('update Asset');
@endphp

{{--
    Every folder as a tree, in the "Library" breadcrumb's dropdown (folder-browser.blade.php), or
    ($siblings) the open folder and the folders next to it, in its own breadcrumb's dropdown.
    A folder opens on click, like a tile (closing the dropdown), and takes drops like one: the
    drag state is on the layout around the page (ListAssets::content()).
--}}
<nav
    aria-label="{{ __('Folder tree') }}"
    x-data="{
        open: {},
        active: @js($current?->id),
        isShown(ancestors) {
            return ancestors.every((id) => this.open[id])
        },
        {{-- Straight away, before the server answers: highlight the folder and unfold it. --}}
        go(id, ancestors = []) {
            this.active = id
            ;[...ancestors, id].forEach((folder) => folder && (this.open[folder] = true))
        },
    }"
    class="cms-folder-tree"
>
    {{-- Opening a folder (here or anywhere) unfolds the tree down to it; folders opened by hand stay open. --}}
    <span hidden wire:key="folder-tree-{{ $scope }}-expand-{{ $current?->id ?? 'root' }}" x-init="active = @js($current?->id); @js($expanded).forEach((id) => (open[id] = true))"></span>

    <ul class="cms-folder-tree-list" role="tree">
        {{-- At the top: the library, or the folder this tree is under (not for the folders next to one). --}}
        @unless ($siblings)
        <li role="treeitem" x-bind:aria-selected="active === @js($under?->id) ? 'true' : 'false'">
            <button
                type="button"
                x-on:click="go(@js($under?->id)); close()"
                wire:click="openFolder(@js($under?->id))"
                data-drop-folder="{{ $under?->id }}"
                data-drop-name="{{ $under?->name ?? __('Library') }}"
                x-on:dragover="enter($event, @js($under?->id))"
                x-on:dragleave="leave($event, @js($under?->id))"
                x-bind:class="{ 'cms-folder-tree-active': active === @js($under?->id), 'cms-folder-tree-over': over === @js($under?->id) && (dragging || isDraggingFiles) }"
                class="cms-folder-tree-item"
            >
                <span class="cms-folder-tree-caret"></span>
                @if ($under)
                    {{ svg('phosphor-folder-open-fill', 'cms-folder-tree-icon') }}
                @else
                    <x-filament::icon icon="phosphor-images" class="cms-folder-tree-icon" />
                @endif
                <span class="cms-folder-tree-name">{{ $under?->name ?? __('Library') }}</span>
            </button>
        </li>
        @endunless

        @foreach ($rows as ['folder' => $folder, 'depth' => $depth, 'ancestors' => $ancestors, 'hasChildren' => $hasChildren])
            @php($folderActions = implode(',', array_filter(['open', \Aurora\Support\AssetAccess::canWrite(auth()->user(), $folder) ? 'renameFolder,moveFolder,deleteFolder' : null, auth()->user()->can('manage-access Asset') && \Aurora\Support\AssetAccess::canWrite(auth()->user(), $folder) ? 'folderAccess' : null])))
            @php($isCurrent = $folder->is($current))
            <li
                role="treeitem"
                x-bind:aria-selected="active === @js($folder->id) ? 'true' : 'false'"
                @if ($hasChildren) x-bind:aria-expanded="open[@js($folder->id)] ? 'true' : 'false'" @endif
                wire:key="folder-tree-{{ $scope }}-{{ $folder->id }}"
                @if ($ancestors !== []) x-show="isShown(@js($ancestors))" x-cloak @endif
                style="--depth: {{ $depth }}"
            >
                <div class="relative">
                    @if ($hasChildren)
                        <button
                            type="button"
                            x-on:click="open[@js($folder->id)] = ! open[@js($folder->id)]"
                            class="cms-folder-tree-caret cms-folder-tree-toggle"
                            x-bind:aria-label="open[@js($folder->id)] ? @js(__('Collapse :folder', ['folder' => $folder->name])) : @js(__('Expand :folder', ['folder' => $folder->name]))"
                        >
                            <span x-bind:class="open[@js($folder->id)] && 'rotate-90'" class="inline-grid transition-transform">
                                {{ svg('phosphor-caret-right-bold', 'size-3') }}
                            </span>
                        </button>
                    @endif

                    <button
                        type="button"
                        x-on:click="go(@js($folder->id), @js($ancestors)); close()"
                        wire:click="openFolder(@js($folder->id))"
                        data-drop-folder="{{ $folder->id }}"
                        data-drop-name="{{ $folder->name }}"
                        @if ($canManageFolders) data-cms-folder="{{ $folder->id }}" data-cms-folder-actions="{{ $folderActions }}" @endif
                        x-on:dragover="enter($event, @js($folder->id))"
                        x-on:dragleave="leave($event, @js($folder->id))"
                        x-bind:class="{ 'cms-folder-tree-active': active === @js($folder->id), 'cms-folder-tree-over': over === @js($folder->id) }"
                        @class(['cms-folder-tree-item', 'cms-folder-tree-active' => $isCurrent])
                    >
                        <span class="cms-folder-tree-caret"></span>
                        <span x-show="active !== @js($folder->id)">{{ svg('phosphor-folder-fill', 'cms-folder-tree-icon') }}</span>
                        <span x-show="active === @js($folder->id)" x-cloak>{{ svg('phosphor-folder-open-fill', 'cms-folder-tree-icon') }}</span>
                        <span class="cms-folder-tree-name">{{ $folder->name }}</span>
                        @if ($folder->access === \Aurora\Support\AssetAccess::CUSTOM)
                            <x-filament::icon icon="phosphor-lock-key-fill" class="cms-access-lock" title="{{ __('Has its own access') }}" />
                        @endif
                        @if ($total = $totals[$folder->id] ?? 0)
                            <span class="cms-folder-tree-count">{{ $total }}</span>
                        @endif
                    </button>
                </div>
            </li>
        @endforeach
    </ul>
</nav>
