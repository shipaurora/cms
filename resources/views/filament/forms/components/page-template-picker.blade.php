{{-- What a new page starts from: "Blank", then each page template drawn small (as the layout
     picker draws layouts), its name and description under it. --}}
@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div {{ $getExtraAttributeBag()->class(['cms-layout-picker']) }} role="radiogroup">
        <label class="cms-layout-picker-option" wire:key="{{ $statePath }}.blank">
            <input type="radio" name="{{ $getId() }}" value="" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" @disabled($isDisabled()) class="sr-only" />
            @if ($blankUrl = $getBlankThumbnailUrl())
                <span class="cms-layout-thumb" x-data="{ ready: false, scale: 0.2 }" x-init="new ResizeObserver(() => scale = $el.clientWidth / 1280).observe($el)" x-bind:class="{ 'cms-layout-thumb-ready': ready }">
                    <iframe src="{{ $blankUrl }}" loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" title="" x-bind:style="{ transform: `scale(${scale})` }" x-on:load="ready = true"></iframe>
                </span>
            @else
                <span class="cms-layout-thumb cms-layout-thumb-empty">{{ svg('phosphor-file', 'size-8') }}</span>
            @endif
            <span class="cms-layout-picker-text">
                <span class="cms-layout-picker-name">{{ __('Blank') }}</span>
                <span class="cms-layout-picker-description">{{ __('An empty page, with the default layout.') }}</span>
            </span>
        </label>

        @foreach ($getTemplates() as $template)
            <label class="cms-layout-picker-option" wire:key="{{ $statePath }}.{{ $template->getKey() }}">
                <input type="radio" name="{{ $getId() }}" value="{{ $template->getKey() }}" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" @disabled($isDisabled()) class="sr-only" />
                <span class="cms-layout-thumb" x-data="{ ready: false, scale: 0.2 }" x-init="new ResizeObserver(() => scale = $el.clientWidth / 1280).observe($el)" x-bind:class="{ 'cms-layout-thumb-ready': ready }">
                    <iframe src="{{ $getThumbnailUrl($template) }}" loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" title="" x-bind:style="{ transform: `scale(${scale})` }" x-on:load="ready = true"></iframe>
                </span>
                <span class="cms-layout-picker-text">
                    <span class="cms-layout-picker-name">{{ $template->title }}</span>
                    @if (filled($template->excerpt))
                        <span class="cms-layout-picker-description">{{ $template->excerpt }}</span>
                    @endif
                </span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
