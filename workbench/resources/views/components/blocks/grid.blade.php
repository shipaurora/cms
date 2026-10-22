@props(['data' => []])

@php
    $columns = max(1, min(6, (int) ($data['columns'] ?? 3)));
    $gap = match ($data['gap'] ?? 'md') { 'sm' => '1rem', 'lg' => '2.5rem', default => '1.5rem' };
    $align = match ($data['align'] ?? 'stretch') { 'start' => 'start', 'center' => 'center', default => 'stretch' };
@endphp

<div
    class="@container"
    data-slot-flow="grid"
    style="--grid-cols: {{ $columns }}; --grid-cols-md: {{ min($columns, 2) }}; --grid-gap: {{ $gap }}; --grid-align: {{ $align }}"
>
    {{ block_slot('items') }}
</div>
