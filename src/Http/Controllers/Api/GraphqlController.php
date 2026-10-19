<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Support\GraphqlSchema;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
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
            variableValues: is_array($variables) ? $variables : null,
            operationName: is_string($operation = $request->input('operationName')) ? $operation : null,
            validationRules: [...DocumentValidator::allRules(), new QueryDepth(self::MAX_DEPTH)],
        );

        return response()->json($result->toArray(config('app.debug') ? DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE));
    }
}
