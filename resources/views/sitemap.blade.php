{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as $page)
    <url>
        <loc>{{ $page->url() }}</loc>
        <lastmod>{{ $page->updated_at->toAtomString() }}</lastmod>
        <priority>{{ $page->is_homepage ? '1.0' : '0.8' }}</priority>
    </url>
@endforeach
</urlset>
