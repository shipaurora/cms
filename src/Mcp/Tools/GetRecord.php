<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Aurora\Support\AiModels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_record')]
#[Description('One record of one of the website\'s models (see list_models), with whether you may change or delete it.')]
#[IsReadOnly]
class GetRecord extends CmsTool
{
    use WorksOnRecords;

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $this->modelArgument($schema),
            'id' => $schema->string()->description('The record\'s id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $record = $this->findRecord($this->findModel($request, $user), $request->get('id'), $user);

        return Response::json([
            'record' => $this->present($record),
            'may_update' => AiModels::allows($user, 'update', $record),
            'may_delete' => AiModels::allows($user, 'delete', $record),
        ]);
    }
}
