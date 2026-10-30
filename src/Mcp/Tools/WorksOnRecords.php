<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Aurora\Support\AiModels;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Mcp\Request;

/**
 * Shared by the record tools, which work on the website's own models (AiModels): only offered
 * to someone who may see at least one of them, and every step asks the model's policy.
 */
trait WorksOnRecords
{
    public function shouldRegister(Request $request): bool
    {
        return AiModels::for($this->actingUser($request)) !== [];
    }

    /**
     * The "model" argument, naming only the models the person may see.
     */
    protected function modelArgument(JsonSchema $schema): mixed
    {
        $user = auth()->user() ?? $this->localUser();
        $names = array_keys(AiModels::for($user instanceof User ? $user : null));

        $argument = $schema->string()->description('The model, by its name in list_models, e.g. "customer".')->required();

        return $names === [] ? $argument : $argument->enum($names);
    }

    /**
     * @return class-string<Model>
     */
    protected function findModel(Request $request, User $user): string
    {
        $name = trim((string) $request->get('model', ''));
        $models = AiModels::for($user);

        return $models[$name]
            ?? $models[str_replace('-', '_', strtolower($name))]
            ?? throw new InvalidArgumentException("No model \"{$name}\" that you can work with. Yours: ".(implode(', ', array_keys($models)) ?: 'none').'. See list_models.');
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function findRecord(string $class, mixed $id, User $user, string $ability = 'view'): Model
    {
        $model = new $class;
        $query = method_exists($class, 'withTrashed') ? $class::query()->withTrashed() : $class::query();
        $record = filled($id) ? $query->whereKey($id)->first() : null;
        $name = AiModels::name($class);

        if ($record === null) {
            throw new InvalidArgumentException("No {$name} with {$model->getKeyName()} \"{$id}\". Use list_records to find it.");
        }

        $this->authorizeOn($user, $ability, $record);
        $this->subject = $record;

        return $record;
    }

    /**
     * @param  class-string<Model>|Model  $model
     */
    protected function authorizeOn(User $user, string $ability, string|Model $model): void
    {
        if (! AiModels::allows($user, $ability, $model)) {
            throw new AuthorizationException;
        }
    }

    /**
     * The record's fields AI may see, translatable ones in every language.
     *
     * @return array<string, mixed>
     */
    protected function present(Model $record): array
    {
        $translatable = method_exists($record, 'getTranslatableAttributes') ? $record->getTranslatableAttributes() : [];
        $fields = [];

        foreach (array_keys($record->getAttributes()) as $field) {
            if (AiModels::readable($record, $field)) {
                $fields[$field] = in_array($field, $translatable, true) ? $record->getTranslations($field) : $record->getAttributeValue($field);
            }
        }

        return $fields;
    }
}
