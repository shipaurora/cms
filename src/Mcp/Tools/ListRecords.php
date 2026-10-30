<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\BlockInput;
use Aurora\Models\User;
use Aurora\Support\AiModels;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_records')]
#[Description('The records of one of the website\'s models (see list_models): searched, filtered on fields, sorted and in pages.')]
#[IsReadOnly]
class ListRecords extends CmsTool
{
    use WorksOnRecords;

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $this->modelArgument($schema),
            'search' => $schema->string()->description('Text to look for in the text fields.'),
            'where' => $schema->object()->description('Fields that must match: {"status": "active"}; a list matches any of its values, null an empty field.'),
            'sort' => $schema->string()->description('The field to sort by. Newest first by default.'),
            'direction' => $schema->string()->enum(['asc', 'desc'])->description('Defaults to desc.'),
            'page' => $schema->integer()->description('1 for the first page (the default).'),
            'per_page' => $schema->integer()->description('Records per page, at most 100. Defaults to 25.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $class = $this->findModel($request, $user);
        $model = new $class;
        $fields = AiModels::fields($class);
        $query = $class::query();

        if (filled($search = trim((string) $request->get('search', '')))) {
            $query->where(function (Builder $query) use ($class, $search): void {
                foreach (AiModels::textFields($class) as $field) {
                    $query->orWhere($field, 'like', '%'.$search.'%');
                }
            });
        }

        foreach ((array) BlockInput::decode($request->get('where', [])) as $field => $value) {
            if (! isset($fields[$field])) {
                throw new InvalidArgumentException("\"{$field}\" isn't a field of {$request->get('model')}. Its fields: ".implode(', ', array_keys($fields)).'.');
            }

            match (true) {
                $value === null => $query->whereNull($field),
                is_array($value) => $query->whereIn($field, $value),
                default => $query->where($field, $value),
            };
        }

        $sort = $request->get('sort') ?: ($model->usesTimestamps() ? $model->getCreatedAtColumn() : $model->getKeyName());

        if (! isset($fields[$sort])) {
            throw new InvalidArgumentException("Can't sort by \"{$sort}\": it isn't a field of {$request->get('model')}.");
        }

        $records = $query
            ->orderBy($sort, $request->get('direction') === 'asc' ? 'asc' : 'desc')
            ->paginate(perPage: min(100, max(1, (int) ($request->get('per_page') ?: 25))), page: max(1, (int) ($request->get('page') ?: 1)));

        return Response::json([
            'records' => collect($records->items())->map(fn (Model $record): array => $this->present($record))->all(),
            'total' => $records->total(),
            'page' => $records->currentPage(),
            'pages' => $records->lastPage(),
        ]);
    }
}
