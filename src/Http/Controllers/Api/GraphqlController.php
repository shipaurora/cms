<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Support\GraphqlSchema;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryDepth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET or POST /api/aurora/graphql: the GraphQL API, for frontends that ask for exactly the
 * fields they need. Read-only: queries, no mutations. Deeply nested queries are turned away.
 */
class GraphqlController
{
    public const MAX_DEPTH = 10;

    /**
     * How deep a query about the schema itself may go (__schema, __type: GraphiQL's and other
     * tools' introspection). Those only describe the schema, never the content, but describe
     * wrapped types ([Page!]!) by nesting, deeper than MAX_DEPTH.
     */
    public const MAX_INTROSPECTION_DEPTH = 15;

    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->input('query');
        // Sent as an object, or (GET) as a JSON string; anything else counts as none.
        $variables = $request->input('variables');
        $variables = is_string($variables) ? json_decode($variables, true) : $variables;

        if (! is_string($query) || $query === '') {
            return response()->json(['errors' => [['message' => 'Send a GraphQL query as "query".']]], 400);
        }

        $result = GraphQL::executeQuery(
            schema: GraphqlSchema::get(),
            source: $query,
            // For each query's scope (GraphqlSchema::scoped()).
            contextValue: $request,
            variableValues: is_array($variables) ? $variables : null,
            operationName: is_string($operation = $request->input('operationName')) ? $operation : null,
            validationRules: [...DocumentValidator::allRules(), new QueryDepth(static::asksOnlyAboutTheSchema($query) ? self::MAX_INTROSPECTION_DEPTH : self::MAX_DEPTH)],
        );

        return response()->json($result->toArray(config('app.debug') ? DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE));
    }

    /**
     * Whether every operation in a query asks only about the schema: each field at its top is
     * __schema or __type (or __typename), from which no content can be reached. A query that
     * doesn't parse isn't one; GraphQL::executeQuery() reports its error.
     */
    protected static function asksOnlyAboutTheSchema(string $query): bool
    {
        try {
            $document = Parser::parse($query);
        } catch (SyntaxError) {
            return false;
        }

        $operations = array_filter(iterator_to_array($document->definitions), fn (mixed $definition): bool => $definition instanceof OperationDefinitionNode);

        foreach ($operations as $operation) {
            foreach ($operation->selectionSet->selections as $selection) {
                if (! $selection instanceof FieldNode || ! str_starts_with($selection->name->value, '__')) {
                    return false;
                }
            }
        }

        return $operations !== [];
    }
}
