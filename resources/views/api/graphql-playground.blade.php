{{-- The GraphQL playground (GraphqlPlaygroundController): GraphiQL on the API itself, whose
     schema (and so its documentation) it reads from the API. Pinned to one version, with its
     hashes checked by the browser. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('GraphQL playground') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/graphiql@3.7.1/graphiql.min.css" integrity="sha384-Mq3vbRBY71jfjQAt/DcjxUIYY33ksal4cgdRt9U/hNPvHBCaT2JfJ/PTRiPKf0aM" crossorigin="anonymous">
    <style>
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; }
        #graphiql { flex: 1; min-height: 0; }
    </style>
</head>
<body>
    @include('aurora::api.bar', [
        'title' => __('GraphQL playground'),
        'api' => __('GraphQL API'),
        'hint' => $needsKey ? __('The API needs a key: put one of your API keys after "Bearer" in the Headers tab below.') : null,
    ])
    <div id="graphiql"></div>

    <script src="https://cdn.jsdelivr.net/npm/react@18.3.1/umd/react.production.min.js" integrity="sha384-DGyLxAyjq0f9SPpVevD6IgztCFlnMF6oW/XQGmfe+IsZ8TqEiDrcHkMLKI6fiB/Z" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/react-dom@18.3.1/umd/react-dom.production.min.js" integrity="sha384-gTGxhz21lVGYNMcdJOyq01Edg0jhn/c22nsx0kyqP0TxaV5WVdsSH1fSDUf5YJj1" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/graphiql@3.7.1/graphiql.min.js" integrity="sha384-w0cGClNeNvIYIRVmYrv5kmQ6CEat8jJb0XBczOFPeWlVjzeMNJBaoeEcWFD8Gad4" crossorigin="anonymous"></script>
    <script>
        ReactDOM.createRoot(document.getElementById('graphiql')).render(React.createElement(GraphiQL, {
            fetcher: GraphiQL.createFetcher({ url: @js($endpoint) }),
            defaultEditorToolsVisibility: @js($needsKey ? 'headers' : true),
            defaultHeaders: @js($needsKey ? "{\n  \"Authorization\": \"Bearer \"\n}" : ''),
            shouldPersistHeaders: false,
            defaultQuery: @js("# Ctrl/⌘ + Enter runs a query. The schema's documentation is under the book icon.\n{\n  pages(perPage: 5) {\n    total\n    items {\n      id\n      title\n      slug\n    }\n  }\n}\n"),
        }));
    </script>
</body>
</html>
