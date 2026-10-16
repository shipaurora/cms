@if ($isInModal())
    @php
        $chosen = $getChosenPage();
    @endphp

    <x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
        <div class="cms-page-picker-chosen-wrp">
            @if ($chosen)
                <div class="cms-page-picker-chosen" wire:key="{{ $getStatePath() }}.{{ $chosen->getKey() }}">
                    <x-filament::icon icon="phosphor-file-text" class="cms-page-picker-chosen-icon" />
                    <span class="cms-page-picker-text">
                        <span class="cms-page-picker-title">{{ $chosen->title }}</span>
                        <span class="cms-page-picker-path">{{ $chosen->path() }}</span>
                    </span>
                    <button
                        type="button"
                        wire:click="callSchemaComponentMethod('{{ $getKey() }}', 'clearPage')"
                        class="cms-page-picker-clear"
                        title="{{ __('Remove') }}"
                        aria-label="{{ __('Remove') }}"
                    >
                        <x-filament::icon icon="phosphor-x-bold" class="size-3.5" />
                    </button>
                </div>
            @endif

            {{ $getAction('pickPage') }}
        </div>
    </x-dynamic-component>
@else
@php
    $rows = $getPageRows();
    $searchable = count($rows) >= \Aurora\Filament\Forms\Components\PagePicker::SEARCHABLE_FROM;
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{ search: '' }"
        {{ $getExtraAttributeBag()->class(['cms-page-picker', 'cms-page-picker-searchable' => $searchable]) }}
    >
        @if ($searchable)
            <x-filament::input.wrapper prefix-icon="phosphor-magnifying-glass" class="cms-page-picker-search">
                <x-filament::input
                    type="search"
                    x-model.debounce.100ms="search"
                    :placeholder="__('Search pages…')"
                    autocomplete="off"
                />
            </x-filament::input.wrapper>
        @endif

        {{-- Opens scrolled to the page already chosen, once the list has a size (in a dialog, it
             has none until the dialog opens). --}}
        <div
            class="cms-page-picker-list"
            role="radiogroup"
            :class="{ 'cms-page-picker-list-searched': search.trim() !== '' }"
            x-init="const observer = new ResizeObserver(() => { if (! $el.clientHeight) return; observer.disconnect(); const row = $el.querySelector('input:checked')?.closest('label'); if (row) $el.scrollTop = row.offsetTop - ($el.clientHeight - row.offsetHeight) / 2 }); observer.observe($el)"
        >
            @foreach ($rows as $row)
                <label
                    wire:key="{{ $statePath }}.{{ $row['id'] }}"
                    class="cms-page-picker-option"
                    style="--depth: {{ $row['depth'] }}"
                    data-search="{{ mb_strtolower($row['title'].' '.$row['path']) }}"
                    x-show="search.trim() === '' || $el.dataset.search.includes(search.trim().toLowerCase())"
                >
                    <input
                        type="radio"
                        name="{{ $getId() }}"
                        value="{{ $row['id'] }}"
                        {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}"
                        @disabled($isDisabled())
                        class="fi-radio-input"
                    />
                    <span class="cms-page-picker-text">
                        <span class="cms-page-picker-title">{{ $row['title'] }}</span>
                        <span class="cms-page-picker-path">{{ $row['path'] }}</span>
                    </span>
                    @if (filled($row['badge']))
                        <span class="cms-page-picker-badge">{{ $row['badge'] }}</span>
                    @endif
                </label>
            @endforeach

            @if ($rows === [])
                <p class="cms-page-picker-empty">{{ __('There are no pages yet.') }}</p>
            @elseif ($searchable)
                <p
                    class="cms-page-picker-empty"
                    x-show="search.trim() !== '' && ! [...$el.parentElement.querySelectorAll('[data-search]')].some((row) => row.dataset.search.includes(search.trim().toLowerCase()))"
                    x-cloak
                >{{ __('No pages match your search.') }}</p>
            @endif
        </div>
    </div>
</x-dynamic-component>
@endif
