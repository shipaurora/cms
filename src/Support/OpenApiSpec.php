<?php

namespace Aurora\Support;

use Aurora\Enums\Api;
use Aurora\Http\Middleware\RequireApiScope;
use Aurora\Models\Setting;
use BackedEnum;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * The REST API's OpenAPI document, for the API explorer (Swagger UI) and any tool that reads
 * one: made from the API's routes each time it's asked for, so it can't fall behind them.
 * Each endpoint's path, path parameters and scope come from its route; its summary and
 * description from its controller's docblock; its query parameters from the controller's
 * ApiParameter attributes. Read-only and public content only, as the API is.
 */
class OpenApiSpec
{
    /**
     * The names the REST API's routes go by (routes/api.php).
     */
    public const ROUTE_PREFIX = 'aurora.api.';

    /**
     * Laravel's pattern for a UUID path parameter (Route::whereUuid()).
     */
    protected const UUID_PATTERN = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $paths = [];

        foreach (static::routes() as $route) {
            $path = '/'.ltrim(Str::after($route->uri(), PublicApi::REST_PREFIX), '/');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = static::operation($route);
            }
        }

        ksort($paths);
        $needsKey = PublicApi::needsKey(Api::Rest);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => trim((Setting::get('site_name') ?: config('app.name')).' REST API'),
                'version' => Updates::installed() ?? 'dev',
                'description' => 'The website\'s live pages, menus, media and public settings as JSON. Read-only: it only shows what visitors may see. '
                    .($needsKey
                        ? 'Every request needs an API key (Settings › Integrations › API keys), sent as Authorization: Bearer <key>; each key has the scopes it may use.'
                        : 'No key is needed: anyone can read it.')
                    .' Add ?locale= to read another language.',
            ],
            'servers' => [['url' => url(PublicApi::REST_PREFIX)]],
            'paths' => $paths,
            'components' => ['securitySchemes' => ['apiKey' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'One of the API keys made under Settings › Integrations › API keys.',
            ]]],
            ...($needsKey ? ['security' => [['apiKey' => []]]] : []),
        ];
    }

    /**
     * The REST API's routes, as they're registered now.
     *
     * @return array<int, Route>
     */
    public static function routes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route): bool => str_starts_with((string) $route->getName(), self::ROUTE_PREFIX),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function operation(Route $route): array
    {
        $controller = $route->getControllerClass();
        $text = $controller !== null ? static::docblock($controller) : '';
        $scope = static::scope($route);
        $declared = $controller !== null ? static::parameters($controller) : [];
        $needsKey = PublicApi::needsKey(Api::Rest);

        $parameters = [];

        // Path parameters: every one the route has, described where the controller says what it is.
        foreach ($route->parameterNames() as $name) {
            $parameter = $declared['path'][$name] ?? null;
            $isUuid = ($route->wheres[$name] ?? null) === self::UUID_PATTERN;

            $parameters[] = array_filter([
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'description' => $parameter?->description ?? ($isUuid ? "The {$name}'s id." : null),
                'schema' => array_filter(['type' => 'string', 'format' => $isUuid ? 'uuid' : $parameter?->format]),
                'example' => $parameter?->example,
            ], fn (mixed $value): bool => $value !== null);
        }

        foreach ($declared['query'] ?? [] as $parameter) {
            $parameters[] = static::queryParameter($parameter);
        }

        // Every endpoint reads in another language when asked to (AllowApi).
        $parameters[] = [
            'name' => 'locale',
            'in' => 'query',
            'required' => false,
            'description' => 'The language to read the content in, by its code. The site\'s default language otherwise.',
            'schema' => ['type' => 'string', 'enum' => array_keys(ContentLocale::all())],
        ];

        $name = Str::after((string) $route->getName(), self::ROUTE_PREFIX);
        // Grouped by what they return: GET /page (one page by its address) is with the pages.
        $tag = Str::before($name, '.');
        $tag = $tag === 'page' ? 'pages' : $tag;
        $hasQuery = ($declared['query'] ?? []) !== [];

        return array_filter([
            'operationId' => Str::camel(str_replace('.', ' ', $name)),
            'tags' => [ucfirst($tag)],
            'summary' => static::summary($text),
            'description' => trim($text.($scope !== null ? "\n\nAPI key scope: `{$scope}`." : '')),
            'parameters' => $parameters,
            'responses' => array_filter([
                '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                '401' => $needsKey ? ['description' => 'No API key, or not a valid one.'] : null,
                '403' => $needsKey && $scope !== null ? ['description' => "The API key doesn't have the `{$scope}` scope."] : null,
                '404' => ['description' => 'Not found, or not something visitors may see. Also when the REST API is switched off.'],
                '422' => $hasQuery ? ['description' => 'A parameter has a value it doesn\'t take.'] : null,
                '429' => ['description' => 'Too many requests: up to 120 a minute.'],
            ]),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function queryParameter(ApiParameter $parameter): array
    {
        $enum = $parameter->enum;

        return array_filter([
            'name' => $parameter->name,
            'in' => 'query',
            'required' => $parameter->required,
            'description' => $parameter->description,
            'schema' => array_filter([
                'type' => $parameter->type,
                'format' => $parameter->format,
                'enum' => $enum !== null ? array_map(fn (BackedEnum $case): string|int => $case->value, $enum::cases()) : null,
            ]),
            'example' => $parameter->example,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The controller's parameters, by where they go: query in order, path by name.
     *
     * @param  class-string  $controller
     * @return array{query?: array<int, ApiParameter>, path?: array<string, ApiParameter>}
     */
    protected static function parameters(string $controller): array
    {
        $parameters = [];

        foreach ((new ReflectionClass($controller))->getAttributes(ApiParameter::class) as $attribute) {
            $parameter = $attribute->newInstance();

            if ($parameter->in === 'path') {
                $parameters['path'][$parameter->name] = $parameter;
            } else {
                $parameters['query'][] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * The API key scope the route needs (RequireApiScope:pages.read), if any.
     */
    protected static function scope(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, RequireApiScope::class.':')) {
                return Str::after($middleware, ':');
            }
        }

        return null;
    }

    /**
     * What the controller's docblock says, without the "GET /pages: " it starts with.
     *
     * @param  class-string  $controller
     */
    protected static function docblock(string $controller): string
    {
        $comment = (string) (new ReflectionClass($controller))->getDocComment();
        $lines = array_map(fn (string $line): string => trim(preg_replace('#^\s*(/\*\*|\*/|\*)\s?#', '', $line)), explode("\n", $comment));
        $text = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($lines))));

        return ucfirst((string) preg_replace('#^[A-Z]+( or [A-Z]+)? /\S*:\s*#', '', $text));
    }

    /**
     * The docblock's first words, up to its first pause: "The site's live pages anyone may see".
     */
    protected static function summary(string $text): string
    {
        return trim(preg_split('/[.,:(]/', $text, 2)[0] ?? $text);
    }
}
