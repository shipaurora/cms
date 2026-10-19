{{-- Settings › Appearance: the registered themes as cards with their pictures (Aurora\Support\Themes):
     a theme with a picture per mode shows the one for the mode the person is in. --}}
@php
    /** @var array<string, array{id: string, name: string, description: ?string, previews: array<string, string>, modes: array<int, string>}> $themes */
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="cms-layout-picker" role="radiogroup">
        @foreach ($themes as $theme)
            <label class="cms-layout-picker-option" wire:key="{{ $statePath }}.{{ $theme['id'] }}">
                <input type="radio" name="{{ $getId() }}" value="{{ $theme['id'] }}" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" class="sr-only" />
                @if ($theme['previews'] !== [])
                    <span class="cms-layout-thumb cms-layout-thumb-ready">
                        @if (count(array_unique($theme['previews'])) > 1)
                            @foreach ($theme['previews'] as $mode => $file)
                                <img src="{{ route('aurora.themes.preview', [$theme['id'], $mode]) }}" alt="" class="cms-theme-preview cms-theme-preview-{{ $mode }}">
                            @endforeach
                        @else
                            <img src="{{ route('aurora.themes.preview', $theme['id']) }}" alt="" class="cms-theme-preview">
                        @endif
                    </span>
                @else
                    <span class="cms-layout-thumb cms-layout-thumb-empty"><x-filament::icon icon="phosphor-paint-brush-broad" class="size-8" /></span>
                @endif
                <span class="cms-layout-picker-text">
                    <span class="cms-layout-picker-name">{{ __($theme['name']) }}</span>
                    @if (filled($theme['description']))
                        <span class="cms-layout-picker-description">{{ __($theme['description']) }}</span>
                    @endif
                    <span class="cms-layout-picker-description">{{ match ($theme['modes']) {
                        ['dark'] => __('Dark only'),
                        ['light'] => __('Light only'),
                        default => __('Light and dark: everyone picks theirs in the user menu'),
                    } }}</span>
                </span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
