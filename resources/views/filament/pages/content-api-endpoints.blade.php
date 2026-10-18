{{-- Settings › Integrations › Content API: what it answers, at which address. --}}
@php
    $endpoints = [
        'GET /pages' => __('The live pages, 50 at a time (?page=2 for more).'),
        'GET /pages/{id}' => __('One page, with its blocks.'),
        'GET /page?path=/about' => __('The page at an address on the website; / is the homepage.'),
        'GET /menus/{location}' => __('The menu in a menu location, such as header.'),
        'GET /settings' => __('The website’s name, colours, header and footer.'),
    ];
@endphp

<div class="cms-api-endpoints">
    <p class="text-sm text-gray-500">{{ __('At :base. Add ?locale=nb for another language.', ['base' => $base]) }}</p>
    <dl>
        @foreach ($endpoints as $endpoint => $what)
            <div>
                <dt><code>{{ $endpoint }}</code></dt>
                <dd>{{ $what }}</dd>
            </div>
        @endforeach
    </dl>
</div>
