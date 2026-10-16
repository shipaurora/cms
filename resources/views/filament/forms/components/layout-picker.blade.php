{{-- In the dialog, each layout's thumbnail: the page drawn with it, at desktop width, scaled
     down to fit. The chosen layout, in the form, is its icon and name. --}}
@php
    $thumbnail = function (\Aurora\Models\Layout $layout) use ($getThumbnailUrl): string {
        $url = $getThumbnailUrl($layout);

        return $url
            ? '<span class="cms-layout-thumb" x-data="{ ready: false, scale: 0.2 }" x-init="new ResizeObserver(() => scale = $el.clientWidth / 1280).observe($el)" x-bind:class="{ \'cms-layout-thumb-ready\': ready }"><iframe src="'.e($url).'" loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true" title="" x-bind:style="{ transform: `scale(${scale})` }" x-on:load="ready = true"></iframe></span>'
            : '<span class="cms-layout-thumb cms-layout-thumb-empty">'.svg($layout->icon ?: 'phosphor-app-window', 'size-8')->toHtml().'</span>';
    };
@endphp

@if ($isInModal())
    @php
        $chosen = $getChosenLayout();
    @endphp

    <x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
        <div class="cms-layout-picker-chosen-wrp">
            @if ($chosen)
                <div class="cms-layout-picker-chosen" wire:key="{{ $getStatePath() }}.{{ $chosen->getKey() }}">
                    <x-filament::icon :icon="$chosen->icon ?: 'phosphor-app-window'" class="cms-layout-picker-chosen-icon" />
                    <span class="cms-layout-picker-text">
                        <span class="cms-layout-picker-name">{{ $chosen->name }}</span>
                        @if (filled($chosen->description))
                            <span class="cms-layout-picker-description">{{ $chosen->description }}</span>
                        @endif
                    </span>
                </div>
            @endif

            {{ $getAction('pickLayout') }}
        </div>
    </x-dynamic-component>
@else
    @php
        $statePath = $getStatePath();
    @endphp

    <x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
        <div {{ $getExtraAttributeBag()->class(['cms-layout-picker']) }} role="radiogroup">
            @foreach ($getLayouts() as $layout)
                <label class="cms-layout-picker-option" wire:key="{{ $statePath }}.{{ $layout->getKey() }}">
                    <input
                        type="radio"
                        name="{{ $getId() }}"
                        value="{{ $layout->getKey() }}"
                        {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}"
                        @disabled($isDisabled())
                        class="sr-only"
                    />
                    {!! $thumbnail($layout) !!}
                    <span class="cms-layout-picker-text">
                        <span class="cms-layout-picker-name">
                            @if (filled($layout->icon))
                                <x-filament::icon :icon="$layout->icon" class="cms-layout-picker-name-icon" />
                            @endif
                            {{ $layout->name }}
                            @if ($layout->is_default)
                                <span class="cms-layout-picker-default">{{ __('Default') }}</span>
                            @endif
                        </span>
                        @if (filled($layout->description))
                            <span class="cms-layout-picker-description">{{ $layout->description }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
    </x-dynamic-component>
@endif
