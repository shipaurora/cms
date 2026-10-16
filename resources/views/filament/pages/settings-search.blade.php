{{-- Settings › Search index: a search to try across the site's pages, kept apart from the
     settings (it's never saved, and typing isn't a change to save). --}}
<div class="grid gap-2">
    <label for="settings-search-query" class="fi-fo-field-label text-sm font-medium text-gray-950 dark:text-white">{{ __('Try a search') }}</label>
    <x-filament::input.wrapper prefix-icon="phosphor-magnifying-glass">
        <x-filament::input
            id="settings-search-query"
            type="search"
            wire:model.live.debounce.400ms="searchQuery"
            :placeholder="__('Words a visitor might type…')"
            autocomplete="off"
        />
    </x-filament::input.wrapper>
</div>
