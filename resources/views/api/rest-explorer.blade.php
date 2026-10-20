{{-- The REST API explorer (RestApiExplorerController): Swagger UI on the API's OpenAPI document,
     which is made from its routes (Aurora\Support\OpenApiSpec). Pinned to one version, with
     its hashes checked by the browser. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('REST API explorer') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css" integrity="sha384-wxLW6kwyHktdDGr6Pv1zgm/VGJh99lfUbzSn6HNHBENZlCN7W602k9VkGdxuFvPn" crossorigin="anonymous">
    <style>
        body { margin: 0; background: #fff; }
        .swagger-ui .topbar { display: none; }
    </style>
</head>
<body>
    @include('aurora::api.bar', ['title' => __('REST API explorer'), 'api' => __('REST API')])
    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" integrity="sha384-wmyclcVGX/WhUkdkATwhaK1X1JtiNrr2EoYJ+diV3vj4v6OC5yCeSu+yW13SYJep" crossorigin="anonymous"></script>
    <script>
        SwaggerUIBundle({
            url: @js($specUrl),
            dom_id: '#swagger-ui',
            deepLinking: true,
            tryItOutEnabled: true,
            displayRequestDuration: true,
        });
    </script>
</body>
</html>
