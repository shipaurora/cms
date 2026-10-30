<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Aurora\Support\AiModels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_models')]
#[Description('The website\'s own kinds of records you may work with (its models, e.g. customers, products or events), with their fields and whether you may create them. Read and change their records with list_records, get_record, save_record and delete_record.')]
#[IsReadOnly]
class ListModels extends CmsTool
{
    use WorksOnRecords;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response
    {
        $models = collect(AiModels::for($user))->map(fn (string $class, string $name): array => array_filter([
            'model' => $name,
            'label' => Str::headline(class_basename($class)),
            'per_site' => AiModels::perSite($class) ?: null,
            'may_create' => AiModels::allows($user, 'create', $class),
            'fields' => array_values(AiModels::fields($class)),
        ], fn (mixed $value): bool => $value !== null));

        return Response::json([
            'models' => $models->values()->all(),
            'notes' => 'Only "writable" fields can be set with save_record. Whether you may change or delete a record is checked per record (get_record says).',
        ]);
    }
}
