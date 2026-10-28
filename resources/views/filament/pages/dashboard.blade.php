{{-- The dashboard's widgets in the person's own layout (Aurora\Support\DashboardLayout), or,
     while customising, a card per widget to drag, resize or remove, and every widget to add
     (Aurora\Filament\Pages\Dashboard). Each widget on it is keyed by its own id: the same
     widget can be there twice. --}}
@php
    use Aurora\Support\DashboardLayout;
    use Illuminate\Support\Js;
@endphp

@if ($customizing)
    <div class="aurora-dashboard-customize">
        <p class="aurora-dashboard-hint">{{ __('Drag the widgets into the order you want, choose how wide each one is, and remove the ones you don’t need. Add any widget, as often as you like. Only your own dashboard changes.') }}</p>

        <div class="aurora-dashboard-catalogue">
            <span>{{ __('Add a widget') }}</span>
            @foreach ($catalogue as $widget)
                <x-filament::button size="sm" color="gray" icon="phosphor-plus" wire:click="addWidget({{ Js::from($widget) }})" wire:key="dashboard-add-{{ md5($widget) }}">
                    {{ DashboardLayout::label($widget) }}
                </x-filament::button>
            @endforeach
        </div>

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
                    x-sortable-item="{{ $item['id'] }}"
                    wire:key="dashboard-card-{{ $item['id'] }}"
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
                            icon="phosphor-x"
                            color="gray"
                            :label="__('Remove')"
                            :tooltip="__('Remove')"
                            wire:click="removeWidget({{ Js::from($item['id']) }})"
                        />
                    </div>
                    <div class="aurora-dashboard-sizes" role="group" aria-label="{{ __('Size') }}">
                        @foreach (['small' => __('Small'), 'medium' => __('Medium'), 'large' => __('Large')] as $size => $label)
                            <button
                                type="button"
                                aria-pressed="{{ $item['size'] === $size ? 'true' : 'false' }}"
                                wire:click="resizeWidget({{ Js::from($item['id']) }}, '{{ $size }}')"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        @if ($layout === [])
            <p class="aurora-dashboard-hint">{{ __('The dashboard is empty. Add a widget above, or go back to the default.') }}</p>
        @endif
    </div>
@else
    <div class="aurora-dashboard">
        @foreach ($layout as $item)
            <div class="aurora-dashboard-item" data-size="{{ $item['size'] }}" wire:key="dashboard-widget-{{ $item['id'] }}">
                @livewire($item['widget'], $item['widget']::getDefaultProperties(), key('dashboard-'.$item['id']))
            </div>
        @endforeach
    </div>
@endif
