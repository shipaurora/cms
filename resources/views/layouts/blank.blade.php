@php
    /**
     * @var \Aurora\Models\Page $page
     * @var \Aurora\Models\Layout|null $layout
     * @var bool $preview  a preview link, or the page not live yet
     * @var bool $embedded  inside the visual editor
     */
    $siteName = \Aurora\Models\Setting::get('site_name', config('app.name'));
    $description = $page->meta_description ?: $page->excerpt;
@endphp
{{-- The blank layout: the page's blocks alone, without a header, menu or footer (a welcome
     page, a campaign). Aurora's own, published to resources/views/vendor/aurora/layouts on
     install: make it the site's there. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->seoTitle() }} · {{ $siteName }}</title>
    @if (filled($description))
        <meta name="description" content="{{ $description }}">
    @endif
    @if ($page->noindex || $page->isRestricted() || $preview || $embedded)
        <meta name="robots" content="noindex">
    @endif
    {{-- The blocks' own layout (columns, spacers, buttons): the page builder's stylesheet. --}}
    <x-page-builder::styles />
    @include('aurora::partials.brand-palette')
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; color: #18181b; }
    </style>
</head>
<body>
    <main>
        <x-aurora::blocks :page="$page" />
    </main>
</body>
</html>
