{{-- While translating: how much is left, and which blocks (outlined on the canvas). --}}
@php
    $untranslated = $this->untranslatedBlockIds();
@endphp

@if ($this->isTranslating())
    @php($summary = $untranslated === [] ? __('Fully translated') : trans_choice(':count block not translated|:count blocks not translated', count($untranslated)))
    <span @class(['cms-editor-missing', 'is-done' => $untranslated === []]) title="{{ $summary }}">
        <span class="cms-editor-missing-long">{{ $summary }}</span>
        {{-- Small screens: just the number (or a tick). --}}
        <span class="cms-editor-missing-short" aria-hidden="true">{{ $untranslated === [] ? '✓' : count($untranslated) }}</span>
    </span>

    {{-- Blocks still showing the page's own language's text. --}}
    <style>
        @foreach ($untranslated as $id)
            .fpb-block[data-id="{{ $id }}"]{{ $loop->last ? '' : ',' }}
        @endforeach
        @if ($untranslated !== [])
            { outline: 1.5px dashed color-mix(in oklch, var(--warning-500) 70%, transparent); outline-offset: 3px; border-radius: 4px; }
        @endif
    </style>
@endif
