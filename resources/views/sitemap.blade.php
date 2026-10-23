{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as $page)
    <url>
        <loc>{{ $page->url() }}</loc>
        <lastmod>{{ $page->updated_at->toAtomString() }}</lastmod>
        <priority>{{ $page->is_homepage ? '1.0' : '0.8' }}</priority>
    </url>
@endforeach
{{-- The website's own addresses (Aurora\Support\Sitemap::add()). --}}
@foreach ($entries ?? [] as $entry)
    <url>
        <loc>{{ $entry['url'] }}</loc>
        @if ($entry['updated_at'])
            <lastmod>{{ $entry['updated_at']->format(DATE_ATOM) }}</lastmod>
        @endif
        @if ($entry['priority'] !== null)
            <priority>{{ number_format($entry['priority'], 1) }}</priority>
        @endif
    </url>
@endforeach
</urlset>
