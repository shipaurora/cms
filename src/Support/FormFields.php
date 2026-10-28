<?php

namespace Aurora\Support;

use Aurora\Forms\Fields;
use Aurora\Forms\FieldType;
use Aurora\Models\Form;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The field types a form can contain (Aurora\Forms\FieldType), Aurora's own and the ones a
 * website or package registers, and what every field shares: its label, key, help text, width,
 * required switch and "Show only when". Register a type in a service provider's boot():
 *
 *     FormFields::register([Turnstile::class, AddressPicker::class]);
 */
class FormFields
{
    /**
     * Keys the form uses for itself: the spam trap and the hidden fields.
     *
     * @var array<int, string>
     */
    public const RESERVED_NAMES = ['website', '_page', '_locale', '_started', '_token'];

    /**
     * The most a file field can be set to take, in megabytes.
     */
    public const MAX_FILE_MB = 20;

    /**
     * Aurora's own types, in the order the form builder lists them.
     *
     * @var array<int, class-string<FieldType>>
     */
    public const BUILT_IN = [
        Fields\Text::class,
        Fields\Email::class,
        Fields\Phone::class,
        Fields\Textarea::class,
        Fields\Select::class,
        Fields\Radio::class,
        Fields\Checkboxes::class,
        Fields\Date::class,
        Fields\File::class,
        Fields\Consent::class,
    ];

    /**
     * Types the website or its packages registered, by their key. One with the key of one of
     * Aurora's replaces it.
     *
     * @var array<string, class-string<FieldType>>
     */
    protected static array $registered = [];

    /**
     * @param  array<int, class-string<FieldType>>  $types
     */
    public static function register(array $types): void
    {
        foreach ($types as $type) {
            if (! is_subclass_of($type, FieldType::class)) {
                throw new InvalidArgumentException("{$type} is not a form field type: extend Aurora\Forms\FieldType.");
            }

            static::$registered[$type::type()] = $type;
        }
    }

    public static function forget(string ...$types): void
    {
        foreach ($types as $type) {
            unset(static::$registered[$type]);
        }
    }

    /**
     * Every type, by its key: Aurora's own first, then the registered ones.
     *
     * @return array<string, class-string<FieldType>>
     */
    public static function all(): array
    {
        $types = [];

        foreach (self::BUILT_IN as $type) {
            $types[$type::type()] = $type;
        }

        return array_replace($types, static::$registered);
    }

    /**
     * A type by its key; a field of a type that's gone (a package removed) is treated as
     * short text, so its form keeps working.
     *
     * @return class-string<FieldType>
     */
    public static function find(string $type): string
    {
        return static::all()[$type] ?? Fields\Text::class;
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, static::all());
    }

    /**
     * Whether a type is one of Aurora's own, drawn by the website's form view; any other is
     * drawn by <x-aurora::form-field>.
     */
    public static function isBuiltIn(string $type): bool
    {
        return in_array(static::all()[$type] ?? null, self::BUILT_IN, true);
    }

    /**
     * Whether a form with these fields has to be sent as multipart/form-data.
     *
     * @param  array<int, array{type: string}>  $fields
     */
    public static function uploads(array $fields): bool
    {
        return collect($fields)->contains(fn (array $field): bool => static::find($field['type'])::uploads());
    }

    /**
     * The scripts a type needs that aren't on this page yet, marked as sent. Kept on the request,
     * not in a static, so a long-running server (Octane) sends them again on the next page.
     *
     * @return array<int, string>
     */
    public static function scriptsOnce(string $type): array
    {
        $sent = request()->attributes->get('aurora.form_scripts', []);

        if (isset($sent[$type])) {
            return [];
        }

        request()->attributes->set('aurora.form_scripts', [...$sent, $type => true]);

        return static::find($type)::scripts();
    }

    /**
     * @return array<int, Block>
     */
    public static function builderBlocks(): array
    {
        return collect(static::all())->map(fn (string $type, string $key): Block => Block::make($key)
            ->label(fn (?array $state): string => filled($state['label'] ?? null) ? $state['label'].' · '.$type::label() : $type::label())
            ->icon($type::icon())
            ->columns(2)
            ->schema(static::schemaFor($type)))
            ->values()
            ->all();
    }

    /**
     * @param  class-string<FieldType>  $type
     * @return array<int, mixed>
     */
    protected static function schemaFor(string $type): array
    {
        $common = [
            TextInput::make('label')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    if (blank($get('name'))) {
                        $set('name', Str::snake(Str::limit((string) $state, 40, '')));
                    }
                }),
            TextInput::make('name')
                ->label(__('Field key'))
                ->helperText(__('Used in exports. Letters, numbers and underscores.'))
                ->regex('/^[a-z0-9_]+$/')
                // The form's own hidden fields (the spam trap among them) use these.
                ->notIn(self::RESERVED_NAMES)
                ->validationMessages(['not_in' => __('This key is used by the form itself. Choose another.')])
                ->required(),
        ];

        return [
            ...$common,
            ...$type::settings(),
            Grid::make(3)->columnSpanFull()->schema([
                TextInput::make('help')->label(__('Help text'))->columnSpan(2)->withAi(),
                ToggleButtons::make('width')->options(['full' => __('Full'), 'half' => __('Half')])->default('full')->inline()->grouped(),
            ]),
            Toggle::make('required')->label(__('Required'))->default($type::requiredByDefault()),
            // Shown only when another field has a given answer (Shared with the website's form
            // block and FormFields::visible()).
            Fieldset::make(__('Show only when'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('show_if_field')
                        ->label(__('This field'))
                        ->placeholder(__('Always show'))
                        ->options(fn (Get $get, $livewire): array => collect($livewire->data['fields'] ?? [])
                            ->map(fn (array $field): array => (array) ($field['data'] ?? []))
                            ->filter(fn (array $data): bool => filled($data['name'] ?? null) && ($data['name'] ?? null) !== $get('name'))
                            ->mapWithKeys(fn (array $data): array => [$data['name'] => $data['label'] ?? $data['name']])
                            ->all())
                        ->live(),
                    TextInput::make('show_if_value')
                        ->label(__('Is'))
                        ->placeholder(__('e.g. Yes'))
                        ->helperText(__('The answer, as it’s written in that field’s choices. A consent box: Yes.'))
                        ->required(fn (Get $get): bool => filled($get('show_if_field')))
                        ->visible(fn (Get $get): bool => filled($get('show_if_field'))),
                ]),
        ];
    }

    /**
     * The fields showing for these answers: a field shown only when another has a given
     * answer is left out otherwise (not asked for, not checked, not kept).
     *
     * @param  array<int, array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}>  $fields
     * @param  array<string, mixed>  $input
     * @return array<int, array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}>
     */
    public static function visible(array $fields, array $input): array
    {
        return array_values(array_filter($fields, function (array $field) use ($input): bool {
            $on = $field['data']['show_if_field'] ?? null;

            if (blank($on)) {
                return true;
            }

            $answer = $input[$on] ?? null;
            $answers = is_array($answer) ? $answer : [$answer === '1' ? 'Yes' : $answer];

            return in_array((string) ($field['data']['show_if_value'] ?? ''), array_map('strval', array_filter($answers, 'is_scalar')), true);
        }));
    }

    /**
     * Inputs for a form's field text in another language, one group per field, with the
     * written language's text as placeholders. Stored as field_translations.{locale}.
     *
     * @return array<int, mixed>
     */
    public static function translationSchema(Form $form, string $locale): array
    {
        return collect($form->definitions($form->sourceLocale()))->map(function (array $field) use ($locale): Fieldset {
            $path = "field_translations.{$locale}.{$field['name']}";
            $data = $field['data'];

            // The type says which of its text is translated; only text it has in the written language shows.
            $inputs = collect(static::find($field['type'])::translatable())
                ->map(fn (string $kind, string $key): mixed => match (true) {
                    $kind === 'options' => TagsInput::make("{$path}.{$key}")
                        ->label(__('Choices'))
                        ->placeholder(implode(', ', (array) ($data[$key] ?? [])))
                        ->helperText(__('The same choices, in the same order.')),
                    blank($data[$key] ?? null) || ! is_string($data[$key]) => null,
                    $kind === 'textarea' => static::translatedWithAi(Textarea::make("{$path}.{$key}")->label(static::textLabel($key))->placeholder($data[$key])->rows(2), $data[$key], $locale),
                    default => static::translatedWithAi(TextInput::make("{$path}.{$key}")->label(static::textLabel($key))->placeholder($data[$key]), $data[$key], $locale),
                })
                ->filter()
                ->values()
                ->all();

            return Fieldset::make($field['label'])
                ->columns(1)
                ->schema([
                    static::translatedWithAi(TextInput::make("{$path}.label")->label(__('Label'))->placeholder($field['label'])->maxLength(255), $field['label'], $locale),
                    ...$inputs,
                ]);
        })->all();
    }

    /**
     * The name of a field's translated text in the translations tab.
     */
    protected static function textLabel(string $key): string
    {
        return match ($key) {
            'placeholder' => __('Placeholder'),
            'help' => __('Help text'),
            'text' => __('Text next to the box'),
            default => Str::headline($key),
        };
    }

    /**
     * A sparkle that translates the default language's text into the box straight away.
     */
    protected static function translatedWithAi(TextInput|Textarea $input, ?string $source, string $locale): TextInput|Textarea
    {
        if (blank($source)) {
            return $input;
        }

        return $input
            ->withAi('A translation of this text from a form on the website: "'.$source.'". Translate the meaning naturally; keep it as short as the original.', $locale)
            ->withoutAiDialog();
    }

    /**
     * File types a file field takes: the ones it lists, else common documents, images and
     * archives. Never ones a browser would run (HTML, SVG, scripts), even when listed.
     *
     * @return array<int, string>
     */
    public static function acceptedExtensions(?string $accept): array
    {
        $listed = array_filter(array_map(fn (string $extension): string => strtolower(trim($extension, " .\t")), explode(',', (string) $accept)));

        return array_values(array_diff(
            $listed ?: ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'csv', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'zip'],
            ['html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'js', 'mjs', 'php', 'phtml', 'phar', 'exe', 'sh'],
        ));
    }

    /**
     * Laravel validation rules for one field definition, from its type.
     *
     * @param  array{type: string, name: string, label: string, required: bool, data: array<string, mixed>}  $field
     * @return array<int, mixed>
     */
    public static function rules(array $field): array
    {
        return static::find($field['type'])::rules($field);
    }
}
