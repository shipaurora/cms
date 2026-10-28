{{-- The dashboard's widgets in the person's own layout (Aurora\Support\DashboardLayout), or,
     while customising, a card per widget to drag, resize or hide (Aurora\Filament\Pages\Dashboard). --}}
@php
    use Aurora\Support\DashboardLayout;
@endphp

@if ($customizing)
    <div class="aurora-dashboard-customize">
        <p class="aurora-dashboard-hint">{{ __('Drag the widgets into the order you want, choose how wide each one is, and hide the ones you don’t need. Only your own dashboard changes.') }}</p>

        @if ($hidden !== [])
            <div class="aurora-dashboard-hidden">
                <span>{{ __('Hidden') }}</span>
                @foreach ($hidden as $widget)
                    <x-filament::button size="sm" color="gray" icon="phosphor-plus" wire:click="showWidget({{ \Illuminate\Support\Js::from($widget) }})" wire:key="dashboard-hidden-{{ md5($widget) }}">
                        {{ DashboardLayout::label($widget) }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif

        <div
            class="aurora-dashboard"
            x-sortable
            data-sortable-animation-duration="200"
            x-on:end.stop="$wire.reorderWidgets($event.target.sortable.toArray())"
        >
            @foreach ($layout as $item)
                <div
                    class="aurora-dashboard-item aurora-dashboard-card"
                    data-size="{{ $item['size'] }}"
                    x-sortable-item="{{ $item['widget'] }}"
                    wire:key="dashboard-card-{{ md5($item['widget']) }}"
                >
                    <div class="aurora-dashboard-card-head">
                        <div class="aurora-dashboard-handle" x-sortable-handle title="{{ __('Drag to move') }}">
                            <x-filament::icon icon="phosphor-dots-six-vertical" />
                            <div>
                                <p class="aurora-dashboard-title">{{ DashboardLayout::label($item['widget']) }}</p>
                                @if (filled($description = DashboardLayout::description($item['widget'])))
                                    <p class="aurora-dashboard-description">{{ $description }}</p>
                                @endif
                            </div>
                        </div>
                        <x-filament::icon-button
                            icon="phosphor-eye-slash"
                            color="gray"
                            :label="__('Hide')"
                            :tooltip="__('Hide')"
                            wire:click="hideWidget({{ \Illuminate\Support\Js::from($item['widget']) }})"
                        />
                    </div>
                    <div class="aurora-dashboard-sizes" role="group" aria-label="{{ __('Size') }}">
                        @foreach (['small' => __('Small'), 'medium' => __('Medium'), 'large' => __('Large')] as $size => $label)
                            <button
                                type="button"
                                aria-pressed="{{ $item['size'] === $size ? 'true' : 'false' }}"
                                wire:click="resizeWidget({{ \Illuminate\Support\Js::from($item['widget']) }}, '{{ $size }}')"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        @if ($layout === [])
            <p class="aurora-dashboard-hint">{{ __('Every widget is hidden. Add one back above, or go back to the default.') }}</p>
        @endif
    </div>
@else
    <div class="aurora-dashboard">
        @foreach ($layout as $item)
            <div class="aurora-dashboard-item" data-size="{{ $item['size'] }}" wire:key="dashboard-widget-{{ md5($item['widget']) }}">
                @livewire($item['widget'], $item['widget']::getDefaultProperties(), key('dashboard-'.md5($item['widget'])))
            </div>
        @endforeach
    </div>
@endif
