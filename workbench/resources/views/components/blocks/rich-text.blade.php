@props(['data' => []])

@php
    $html = rich_text($data['body'] ?? null);
@endphp

{{-- Written right on the page in the visual editor (Editable::richText()). --}}
@shows('body', (string) $html)
    <div @class(['prose-cms', 'max-w-3xl' => ($data['width'] ?? 'prose') === 'prose']) @editable('body')>{{ $html }}</div>
@endshows
