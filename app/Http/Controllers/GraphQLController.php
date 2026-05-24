<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\GraphQL\Context;
use App\GraphQL\Schema;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GraphQLController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->isJson() ? $request->json()->all() : $request->all();

        $query = (string) ($payload['query'] ?? '');
        $variables = $payload['variables'] ?? null;
        if ($variables !== null && ! is_array($variables)) {
            return response()->json(['errors' => [['message' => 'variables must be an object']]], 400);
        }
        $operationName = $payload['operationName'] ?? null;

        if ($query === '') {
            return response()->json(['errors' => [['message' => 'Empty query']]], 400);
        }

        if (mb_strlen($query) > 16000) {
            return response()->json(['errors' => [['message' => 'Query payload exceeds 16KB']]], 413);
        }

        $context = new Context($request->user());

        $debugFlags = config('app.debug') ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE : DebugFlag::NONE;

        $result = GraphQL::executeQuery(
            schema: Schema::build(),
            source: $query,
            rootValue: null,
            contextValue: $context,
            variableValues: $variables,
            operationName: $operationName,
        );

        return response()->json($result->toArray($debugFlags));
    }
}
