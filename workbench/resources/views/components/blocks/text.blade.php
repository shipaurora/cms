@props(['data' => []])

@php
    $size = match ($data['size'] ?? 'base') {
        'sm' => 'text-sm/6',
        'lead' => 'text-xl/8 text-ink',
        default => 'text-base/7',
    };
@endphp

<p @class(['max-w-3xl text-pretty whitespace-pre-line text-ink-muted', $size, 'mx-auto text-center' => ($data['align'] ?? 'left') === 'center']) @editable('body')>{{ plain_text($data['body'] ?? '') }}</p>
