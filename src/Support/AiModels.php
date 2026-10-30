<?php

namespace Aurora\Support;

use Aurora\Blocks\Blocks;
use Aurora\Mcp\HiddenFromAi;
use Aurora\Models\Concerns\BelongsToSite;
use Aurora\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;
use UnitEnum;

/**
 * The website's own models (app/Models: customers, products, events …), for AI apps and the
 * assistant to read and change through the record tools (list_records and the others).
 *
 * Each model is reached only through its policy: someone sees a model when its policy lets
 * them viewAny, and every read and change asks the policy again. A model without a policy is
 * for Super Admins only. Aurora's own models, and the website's that extend them, have tools
 * of their own and are left out, as is a model marked #[HiddenFromAi]. So are fields the
 * model hides ($hidden) and encrypted ones: never shown, never written.
 *
 * The folders searched are config('aurora.mcp.models'), app/Models by default.
 */
class AiModels
{
    /**
     * Models added by hand (a package, a test), besides the ones found in the folders.
     *
     * @var array<int, class-string<Model>>
     */
    protected static array $registered = [];

    /**
     * @var array<string, class-string<Model>>|null
     */
    protected static ?array $found = null;

    /**
     * @param  array<int, class-string<Model>>  $models
     */
    public static function register(array $models): void
    {
        static::$registered = array_values(array_unique([...static::$registered, ...$models]));
        static::$found = null;
    }

    /**
     * Take models away again, and look in the folders afresh (a test).
     *
     * @param  array<int, class-string<Model>>  $models
     */
    public static function forget(array $models = []): void
    {
        static::$registered = array_values(array_diff(static::$registered, $models));
        static::$found = null;
    }

    /**
     * Every model AI may be offered, by its name in the tools ("customer", "shop.product").
     *
     * @return array<string, class-string<Model>>
     */
    public static function all(): array
    {
        if (static::$found !== null) {
            return static::$found;
        }

        $models = [];

        foreach ((array) config('aurora.mcp.models', [app_path('Models')]) as $key => $value) {
            [$path, $namespace] = is_string($key) ? [$key, $value] : [$value, null];

            foreach (static::inFolder($path, $namespace) as $name => $class) {
                $models[$name] ??= $class;
            }
        }

        foreach (static::$registered as $class) {
            $models[Str::snake(class_basename($class))] ??= $class;
        }

        return static::$found = array_filter($models, fn (string $class): bool => static::offerable($class));
    }

    /**
     * The models someone may see: their policy's viewAny, or, without a policy, Super Admins.
     *
     * @return array<string, class-string<Model>>
     */
    public static function for(?User $user): array
    {
        return $user === null ? [] : array_filter(static::all(), fn (string $class): bool => static::allows($user, 'viewAny', $class));
    }

    /**
     * Ask the model's policy. A model without one is for Super Admins only.
     *
     * @param  class-string<Model>|Model  $model
     */
    public static function allows(User $user, string $ability, string|Model $model): bool
    {
        if (Gate::getPolicyFor($model) === null) {
            return $user->isSuperAdmin();
        }

        return $user->can($ability, $model);
    }

    /**
     * The model's name in the tools.
     *
     * @param  class-string<Model>|Model  $model
     */
    public static function name(string|Model $model): ?string
    {
        $class = is_string($model) ? $model : $model::class;

        return array_search($class, static::all(), true) ?: null;
    }

    /**
     * The model's fields as AI reads them: from its table, with the labels, options and help
     * of its Filament resource's form when it has one.
     *
     * @param  class-string<Model>  $class
     * @return array<string, array<string, mixed>>
     */
    public static function fields(string $class): array
    {
        $model = new $class;
        $form = collect(static::formFields($class))->keyBy('name');
        $translatable = method_exists($model, 'getTranslatableAttributes') ? $model->getTranslatableAttributes() : [];
        $fields = [];

        foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
            $name = $column['name'];

            if (! static::readable($model, $name)) {
                continue;
            }

            $cast = $model->getCasts()[$name] ?? null;
            $described = (array) $form->get($name, []);
            $options = $described['options'] ?? (is_string($cast) && enum_exists($cast) ? static::enumOptions($cast) : null);

            $fields[$name] = array_filter([
                'name' => $name,
                'type' => $described['type'] ?? (is_string($cast) && ! enum_exists($cast) ? $cast : $column['type_name']),
                'label' => $described['label'] ?? null,
                'options' => $options,
                'multiple' => $described['multiple'] ?? null,
                'nullable' => $column['nullable'] ?: null,
                'required' => ($described['required'] ?? false) ?: null,
                'translatable' => in_array($name, $translatable, true) ? 'an object of language code => text, or text in the current language' : null,
                'writable' => static::writable($model, $name),
                'help' => $described['help'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $fields;
    }

    /**
     * The fields search looks in: the readable text columns.
     *
     * @param  class-string<Model>  $class
     * @return array<int, string>
     */
    public static function textFields(string $class): array
    {
        $model = new $class;

        return collect($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()))
            ->filter(fn (array $column): bool => (bool) preg_match('/char|text|json|string/i', $column['type_name']) && static::readable($model, $column['name']))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function perSite(string $class): bool
    {
        return in_array(BelongsToSite::class, class_uses_recursive($class), true);
    }

    /**
     * Shown to AI: not hidden by the model ($hidden, $visible) and not encrypted.
     */
    public static function readable(Model $model, string $field): bool
    {
        $cast = strtolower((string) ($model->getCasts()[$field] ?? ''));

        return ! in_array($field, $model->getHidden(), true)
            && ($model->getVisible() === [] || in_array($field, $model->getVisible(), true))
            && ! str_contains($cast, 'encrypted');
    }

    /**
     * Written by AI: readable, fillable, and not the key, the site or a timestamp, which the
     * model keeps itself.
     */
    public static function writable(Model $model, string $field): bool
    {
        $kept = [$model->getKeyName(), 'site_id', $model->getCreatedAtColumn(), $model->getUpdatedAtColumn(), 'deleted_at'];

        return static::readable($model, $field) && $model->isFillable($field) && ! in_array($field, $kept, true);
    }

    /**
     * @return array<string, class-string<Model>>
     */
    protected static function inFolder(string $path, ?string $namespace): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $namespace = trim($namespace ?? Blocks::namespaceFor($path), '\\');
        $models = [];

        foreach ((new Finder)->files()->in($path)->name('*.php')->sortByName() as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = $namespace.'\\'.$relative;

            if (class_exists($class)) {
                $models[implode('.', array_map(Str::snake(...), explode('\\', $relative)))] = $class;
            }
        }

        return $models;
    }

    protected static function offerable(string $class): bool
    {
        if (! is_subclass_of($class, Model::class) || is_subclass_of($class, Pivot::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || $reflection->getAttributes(HiddenFromAi::class) !== []) {
            return false;
        }

        // Aurora's own (pages, menus, users …) have tools of their own.
        foreach ([$class, ...class_parents($class)] as $type) {
            if (str_starts_with($type, 'Aurora\\')) {
                return false;
            }
        }

        try {
            $model = new $class;

            return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The fields of the model's resource's form in the admin, described as for blocks.
     *
     * @param  class-string<Model>  $class
     * @return array<int, array<string, mixed>>
     */
    protected static function formFields(string $class): array
    {
        try {
            $resource = Filament::getPanel('admin')->getModelResource($class);

            return $resource === null ? [] : BlockCatalogue::fields($resource::form(Schema::make())->getComponents(withHidden: true));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  class-string<UnitEnum>  $enum
     * @return array<string, string>
     */
    protected static function enumOptions(string $enum): array
    {
        return collect($enum::cases())
            ->mapWithKeys(fn (UnitEnum $case): array => [($case instanceof BackedEnum ? $case->value : $case->name) => method_exists($case, 'getLabel') ? (string) $case->getLabel() : $case->name])
            ->all();
    }
}
