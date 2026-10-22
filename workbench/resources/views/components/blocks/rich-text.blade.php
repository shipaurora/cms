@props(['data' => []])

@php
    $html = rich_text($data['body'] ?? null);
@endphp

@if (filled((string) $html))
    <div @class(['prose-cms', 'max-w-3xl' => ($data['width'] ?? 'prose') === 'prose'])>{{ $html }}</div>
@elseif (is_editing())
    <div class="rounded-xl border border-dashed border-line p-6 text-center text-sm text-ink-soft">{{ __('Rich text: write in the panel on the right →') }}</div>
@endif
