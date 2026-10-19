{{-- Overrides the package's canvas-block so a block's body on the canvas carries what its
     element does on the website (public-block): the fpb-el class with its CSS class, and
     data-block. The website's stylesheet, shown on the canvas, then styles it the same way. --}}
@props(['block', 'selectedId' => null])

@php
    use CarlJanzell\FilamentPageBuilder\PageBuilder;
    use CarlJanzell\FilamentPageBuilder\Support\BlockStyle;
    use CarlJanzell\FilamentPageBuilder\Support\BlockTree;

    $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
    $anchor = BlockTree::isValidAnchor($block['anchor'] ?? null) ? $block['anchor'] : null;
    $label = $block['label'] ?? $block['type'];
    $style = BlockStyle::compile(is_array($block['style'] ?? null) ? $block['style'] : []);
    $selected = $selectedId === $block['id'];
    $align = is_array($block['style'] ?? null) ? ($block['style']['text_align'] ?? null) : null;
@endphp

<div
    class="fpb-block"
    data-id="{{ $block['id'] }}"
    data-type="{{ $block['type'] }}"
    data-parent="{{ $block['parent'] ?? '' }}"
    data-slot="{{ $block['slot'] ?? '' }}"
    data-has-content="{{ $block['hasContent'] ? 'true' : 'false' }}"
    @if ($anchor) id="{{ $anchor }}" @endif
    @if ($block['isContainer'] ?? false) data-container="true" @endif
    @if ($selected) data-selected="true" @endif
    @unless ($block['isKnown']) data-unknown="true" @endunless
    @if ($style['sized']) data-fpb-sized="height" @endif
    @if ($style['outer'] !== '') style="{{ $style['outer'] }}" @endif
    @foreach ($settings as $token => $value)
        @if (is_string($token) && preg_match('/^[a-z-]+$/', $token) && is_string($value))
            data-fpb-{{ $token }}="{{ $value }}"
        @endif
    @endforeach
    wire:click.stop="selectBlock('{{ $block['id'] }}')"
    wire:key="fpb-block-{{ $block['id'] }}"
>
    <div class="fpb-block-bar">
        <button
            type="button"
            class="fpb-block-handle"
            title="{{ __('page-builder::chrome.drag_to_move') }}"
            aria-label="{{ __('page-builder::chrome.move', ['label' => $label]) }}"
            :draggable="!narrow"
            x-on:dragstart.stop="startMove($event, '{{ $block['id'] }}')"
            x-on:dragend="clearDrag()"
            x-on:click.stop
        >&#8942;&#8942;</button>
        <span class="fpb-block-label">{{ $label }}</span>
        @if ($selected && $block['isKnown'] && ($block['isEditable'] ?? true))
            {{-- The same tooltips as the Style tab's alignment buttons, each a whole phrase.
                 Gluing "Align :side" to a translated side word read "Выравнивание по По
                 центру" in Russian and "Aligner à Justifier" in French. The keys stay the
                 stored `text_align` values, which are CSS and do not move. --}}
            @php
                $alignButtons = [
                    'left' => [__('page-builder::style.alignment.align_left'), 'heroicon-m-bars-3-bottom-left'],
                    'center' => [__('page-builder::style.common.centre'), 'heroicon-m-bars-3'],
                    'right' => [__('page-builder::style.alignment.align_right'), 'heroicon-m-bars-3-bottom-right'],
                    'justify' => [__('page-builder::style.common.justify'), 'heroicon-m-bars-4'],
                ];
            @endphp
            <span class="fpb-block-quick" role="group" aria-label="{{ __('page-builder::chrome.align_text') }}">
                @foreach ($alignButtons as $side => [$alignLabel, $icon])
                    <button
                        type="button"
                        title="{{ $alignLabel }}"
                        aria-label="{{ $alignLabel }}"
                        aria-pressed="{{ $align === $side ? 'true' : 'false' }}"
                        @if ($align === $side) data-active="true" @endif
                        wire:click.stop="setBlockStyle('{{ $block['id'] }}', 'text_align', '{{ $align === $side ? '' : $side }}')"
                    ><x-filament::icon :icon="$icon" class="fpb-quick-icon" /></button>
                @endforeach
            </span>
        @endif
        <span class="fpb-block-tools">
            <button
                type="button"
                class="fpb-block-nudge"
                title="{{ __('page-builder::chrome.move_up') }}"
                aria-label="{{ __('page-builder::chrome.move_named_up', ['label' => $label]) }}"
                x-show="narrow"
                x-cloak
                x-on:click.stop="moveSelected('{{ $block['id'] }}', -1)"
            >↑</button>
            <button
                type="button"
                class="fpb-block-nudge"
                title="{{ __('page-builder::chrome.move_down') }}"
                aria-label="{{ __('page-builder::chrome.move_named_down', ['label' => $label]) }}"
                x-show="narrow"
                x-cloak
                x-on:click.stop="moveSelected('{{ $block['id'] }}', 1)"
            >↓</button>
            <button
                type="button"
                class="fpb-block-edit"
                title="{{ __('page-builder::chrome.edit') }}"
                aria-label="{{ __('page-builder::chrome.edit_named', ['label' => $label]) }}"
                x-show="narrow"
                x-cloak
                x-on:click.stop="showWorkspace('settings')"
            >{{ __('page-builder::chrome.edit') }}</button>
            <button type="button" title="{{ __('page-builder::chrome.duplicate') }}" aria-label="{{ __('page-builder::chrome.duplicate_named', ['label' => $label]) }}"
                    wire:click.stop="duplicateBlock('{{ $block['id'] }}')">&#10697;</button>
            <button type="button" class="fpb-block-more" title="{{ __('page-builder::chrome.more_actions') }}" aria-label="{{ __('page-builder::chrome.more_actions_named', ['label' => $label]) }}"
                    x-on:click.stop="openMenu($event, '{{ $block['id'] }}')">&#8943;</button>
            <button type="button" title="{{ __('page-builder::chrome.delete') }}" aria-label="{{ __('page-builder::chrome.delete_named', ['label' => $label]) }}"
                    x-on:click.stop="remove('{{ $block['id'] }}', {{ $block['hasContent'] ? 'true' : 'false' }})">&#10005;</button>
        </span>
    </div>

    <div
        class="fpb-block-body fpb-el{{ $style['class'] ? ' '.$style['class'] : '' }}"
        data-block="{{ $block['type'] }}"
        @if ($style['inner'] !== '') style="{{ $style['inner'] }}" @endif
    >
        @include('page-builder::components.background', ['layer' => $style['background']])
        @if ($block['isKnown'] && $block['view'])
            @php
                PageBuilder::editing($block['id'], $block['type'], $block['data'] ?? []);

                if ($block['isContainer'] ?? false) {
                    PageBuilder::provideSlots($block['slotNames'] ?? [], function (string $name) use ($block, $selectedId): string {
                        return view('page-builder::components.canvas-slot', [
                            'parent' => $block['id'],
                            'slot' => $name,
                            'children' => $block['children'][$name] ?? [],
                            'selectedId' => $selectedId,
                        ])->render();
                    });
                }
            @endphp
            {!! PageBuilder::renderSafely(function () use ($block): string {
                if (str_contains((string) $block['view'], '::')) {
                    return view($block['view'], ['data' => $block['data']])->render();
                }

                return view('page-builder::components.dynamic-block', [
                    'view' => $block['view'],
                    'data' => $block['data'],
                ])->render();
            }) !!}
        @elseif (! $block['isKnown'])
            <p class="fpb-block-retired">
                {{ PageBuilder::lineWithMarkup('page-builder::chrome.retired', ['type' => '<code>'.e($block['type']).'</code>']) }}
            </p>
        @endif
    </div>

    @if ($selected && $block['isKnown'])
        <span
            class="fpb-resize fpb-resize-x"
            title="{{ __('page-builder::chrome.resize_width') }}"
            aria-hidden="true"
            x-on:pointerdown.stop.prevent="startResize($event, '{{ $block['id'] }}', 'width')"
            x-on:dblclick.stop="resetSize('{{ $block['id'] }}', 'width')"
            x-on:click.stop
        ></span>
        <span
            class="fpb-resize fpb-resize-y"
            title="{{ __('page-builder::chrome.resize_height') }}"
            aria-hidden="true"
            x-on:pointerdown.stop.prevent="startResize($event, '{{ $block['id'] }}', 'height')"
            x-on:dblclick.stop="resetSize('{{ $block['id'] }}', 'height')"
            x-on:click.stop
        ></span>
    @endif
</div>
