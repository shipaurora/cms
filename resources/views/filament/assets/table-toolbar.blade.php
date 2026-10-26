@php
    /** @var \Aurora\Filament\Resources\Assets\Pages\ListAssets $page */
    $table = $page->getTable();
    $sortable = collect($table->getColumns())->filter(fn ($column): bool => $column->isSortable());
@endphp

{{-- The start of the media library's toolbar (ListAssets::tableToolbar()). The table would
     draw these in a bar of their own between its toolbar and its files, which would separate
     the folders drawn above the files from them; that bar is hidden (components.css). --}}
@if ($table->isSelectionEnabled())
    <input
        type="checkbox"
        aria-label="{{ __('filament-tables::table.fields.bulk_select_page.label') }}"
        x-bind:checked="
            const recordsOnPage = getRecordsOnPage()

            if (recordsOnPage.length && areRecordsSelected(recordsOnPage)) {
                $el.checked = true
                $el.indeterminate = false

                return 'checked'
            }

            $el.checked = false
            $el.indeterminate = recordsOnPage.length && areRecordsPartiallySelected(recordsOnPage)

            return null
        "
        x-on:click="toggleSelectRecordsOnPage"
        {{-- Re-evaluates "checked" after a Livewire request. --}}
        wire:key="{{ $page->getId() }}.media.select-page.{{ Str::random() }}"
        x-tooltip="{ content: @js(__('Select every file on this page')), theme: $store.theme }"
        class="fi-ta-page-checkbox fi-checkbox-input cms-media-select-page"
    />
@endif

{{-- In the list: what the column headings would have sorted by. --}}
@if ($page->display === 'list' && $sortable->isNotEmpty())
    <label class="cms-media-sort">
        <span class="fi-sr-only">{{ __('Sort by') }}</span>
        <x-filament::input.wrapper>
            <x-filament::input.select
                x-data="{ sort: $wire.$entangle('tableSort', true) }"
                x-model="sort"
            >
                <option value="">{{ __('Newest first') }}</option>
                @foreach ($sortable as $column)
                    @if ($column->getName() === 'created_at')
                        <option value="created_at:asc">{{ __('Oldest first') }}</option>
                    @else
                        <option value="{{ $column->getName() }}:asc">{{ __(':column, A to Z', ['column' => $column->getLabel()]) }}</option>
                        <option value="{{ $column->getName() }}:desc">{{ __(':column, Z to A', ['column' => $column->getLabel()]) }}</option>
                    @endif
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>
@endif
