<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\BlockInput;
use Aurora\Models\User;
use Aurora\Support\AiModels;
use Aurora\Support\BlockCatalogue;
use Aurora\Support\ContentLocale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('save_record')]
#[Description('Create a record of one of the website\'s models (see list_models), or change one by its id. Only the fields list_models marks "writable"; the ones you leave out stay as they are.')]
class SaveRecord extends CmsTool
{
    use WorksOnRecords;

    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $this->modelArgument($schema),
            'id' => $schema->string()->description('The record to change. Leave out to create one.'),
            'data' => $schema->object()->description('The fields to set: {"name": "Ada Lovelace", "email": "ada@example.com"}. A translatable field takes {"en": "…", "nb": "…"} or text in the current language.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $class = $this->findModel($request, $user);
        $name = AiModels::name($class);
        $creating = blank($request->get('id'));
        $record = $creating ? new $class : $this->findRecord($class, $request->get('id'), $user, 'update');

        if ($creating) {
            $this->authorizeOn($user, 'create', $class);
        }

        $data = BlockInput::decode($request->get('data'));

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidArgumentException('"data" takes an object of field names and values.');
        }

        $fields = AiModels::fields($class);
        $writable = array_keys(array_filter($fields, fn (array $field): bool => $field['writable']));
        $errors = [];

        foreach (array_keys($data) as $field) {
            if (! in_array($field, $writable, true)) {
                $errors[] = "\"{$field}\" can't be set. The fields you can set: ".(implode(', ', $writable) ?: 'none').'.';
            }
        }

        // The admin form's choices and required fields, where the model has a resource.
        $chosen = array_intersect_key($data, array_filter($fields, fn (array $field): bool => isset($field['options'])));
        array_push($errors, ...BlockCatalogue::checkFields($name, array_values(array_intersect_key($fields, $chosen)), $chosen));

        if ($creating) {
            foreach ($fields as $field => $described) {
                if (($described['required'] ?? false) && $described['writable'] && blank($data[$field] ?? null)) {
                    $errors[] = "\"{$field}\" is required.";
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException("Not saved:\n- ".implode("\n- ", $errors));
        }

        foreach ($data as $field => $value) {
            if (isset($fields[$field]['translatable'])) {
                is_array($value) ? $record->setTranslations($field, $value) : $record->setTranslation($field, ContentLocale::current(), $value);
            } else {
                $record->setAttribute($field, $value);
            }
        }

        try {
            $record->save();
        } catch (QueryException $exception) {
            return Response::error('Not saved: the database refused it ('.Str::limit(Str::before($exception->getPrevious()?->getMessage() ?? $exception->getMessage(), ' (Connection'), 300).'). See list_models for the fields.');
        }

        $this->did(($creating ? 'Created' : 'Changed')." {$name} {$record->getKey()}", $record);

        return Response::json([
            'message' => $creating ? "Created the {$name}." : "Saved the {$name}.",
            'record' => $this->present($record->refresh()),
        ]);
    }
}
