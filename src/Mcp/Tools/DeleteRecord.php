<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Aurora\Support\AiModels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_record')]
#[Description('Delete a record of one of the website\'s models (see list_models). Only when the user asks for it: confirm which record first.')]
#[IsDestructive]
class DeleteRecord extends CmsTool
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
        $class = $this->findModel($request, $user);
        $record = $this->findRecord($class, $request->get('id'), $user, 'delete');
        $name = AiModels::name($class);

        $record->delete();
        $this->did("Deleted {$name} {$record->getKey()}", $record);

        return Response::json(['message' => "Deleted the {$name}."]);
    }
}
