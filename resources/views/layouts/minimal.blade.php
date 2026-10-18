@php
    /**
     * @var \Aurora\Models\Page $page
     * @var \Aurora\Models\Layout|null $layout
     * @var bool $preview  a preview link, or the page not live yet
     * @var bool $embedded  inside the visual editor
     */
    $siteName = \Aurora\Models\Setting::get('site_name', config('app.name'));
    $description = $page->meta_description ?: $page->excerpt;
    $menu = null;
@endphp
{{-- The minimal layout: the logo alone, no menu or footer, for campaigns. Published to
     resources/views/vendor/aurora/layouts on install: make it the site's there. --}}
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
    @include('aurora::partials.brand-palette')
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; color: #18181b; }
        .site-header { display: flex; align-items: center; justify-content: space-between; gap: 2rem; padding: 1.25rem 1.5rem; }
        .site-header a { color: inherit; text-decoration: none; }
        .site-header nav ul { display: flex; gap: 1.5rem; margin: 0; padding: 0; list-style: none; }
        .site-footer { padding: 2rem 1.5rem; color: #71717a; font-size: 0.875rem; }
    </style>
</head>
<body>
    <header class="site-header">
        <a href="{{ url('/') }}"><strong>{{ $siteName }}</strong></a>
        @if ($menu)
            <nav aria-label="{{ __('Main') }}">
                <ul>
                    @foreach ($menu->menuItems as $item)
                        <li><a href="{{ $item->url ?? '#' }}">{{ $item->title }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>

    <main>
        <x-aurora::blocks :page="$page" />
    </main>
</body>
</html>
