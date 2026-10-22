@props(['data' => []])

@php
    $justify = match ($data['justify'] ?? 'start') {
        'center' => 'center',
        'end' => 'flex-end',
        'between' => 'space-between',
        default => 'flex-start',
    };
@endphp

<div data-slot-flow="row" style="--justify: {{ $justify }}">
    {{ block_slot('buttons') }}
</div>
