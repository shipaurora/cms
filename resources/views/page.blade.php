@php
    /** @var \Aurora\Models\Page $page */
@endphp
{{-- A plain page, until the site has its own (config('aurora.page_view')): the page's blocks
     in a bare document, with its title and description. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seoTitle() }}</title>
    @if (filled($description = $page->meta_description ?: $page->excerpt))
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($page->noindex || $preview || $embedded)
        <meta name="robots" content="noindex">
    @endif
    @include('aurora::partials.brand-palette')
</head>
<body>
    <main>
        <x-aurora::blocks :page="$page" />
    </main>
</body>
</html>
