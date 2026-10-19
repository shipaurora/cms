{{-- Settings › Integrations › REST API / GraphQL API: how to call it (Aurora\Support\PublicApi). --}}
@php
    use Aurora\Enums\Api;
    use Aurora\Support\PublicApi;

    /** @var Api $api */
    $endpoints = [
        'GET /pages' => __('The live pages, 50 at a time (?page=2 for more). ?parent={id} for the pages under one.'),
        'GET /pages/{id}' => __('One page, with its blocks: images, links and forms in them come with what they point at.'),
        'GET /page?path=/about' => __('The page at an address on the website; / is the homepage.'),
        'GET /media' => __('The files the website’s pages use, with their sizes (?type=image for one kind).'),
        'GET /media/{id}' => __('One of those files.'),
        'GET /menus/{location}' => __('The menu in a menu location, such as header.'),
        'GET /settings' => __('The website’s name, colours and the settings its blocks make public.'),
    ];

    $example = <<<'GRAPHQL'
        {
          page(path: "/") {
            title
            seo { title description }
            blocks
          }
          menu(location: "header") {
            items { title url children { title url } }
          }
        }
        GRAPHQL;
@endphp

<div class="cms-api-endpoints">
    @if ($api === Api::Rest)
        <p class="text-sm text-gray-500">{{ __('At :base. Add ?locale=nb for another language.', ['base' => url(PublicApi::REST_PREFIX)]) }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ __('Only what visitors may see: never drafts, pages behind a password or sign-in, files no page uses, or private settings.') }}</p>
        <dl>
            @foreach ($endpoints as $endpoint => $what)
                <div>
                    <dt><code>{{ $endpoint }}</code></dt>
                    <dd>{{ $what }}</dd>
                </div>
            @endforeach
        </dl>
    @else
        <p class="text-sm text-gray-500">{{ __('Send queries to :url as a POST with a JSON body {"query": "…"}. Add ?locale=nb for another language. Tools such as GraphiQL, Altair or Postman read the schema from it.', ['url' => url(PublicApi::GRAPHQL_PATH)]) }}</p>
        <p class="cms-api-example-label">{{ __('For example: the homepage and the header menu') }}</p>
        <pre class="cms-api-example"><code>{{ $example }}</code></pre>
    @endif
</div>
