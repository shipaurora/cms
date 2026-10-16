<?php

namespace Aurora\Support;

use Aurora\Models\Form;
use Filament\Forms\Components\Builder\Block;
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
use Illuminate\Validation\Rule;

/**
 * The field types a form can contain, defined once: how editors configure them (Filament
 * Builder blocks), how answers are validated, and which view renders them on the site.
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
     * @var array<string, array{0: string, 1: string}>
     */
    public const TYPES = [
        'text' => ['Short text', 'phosphor-pencil-simple'],
        'email' => ['Email', 'phosphor-envelope-simple'],
        'phone' => ['Phone', 'phosphor-phone'],
        'textarea' => ['Long text', 'phosphor-text-align-left'],
        'select' => ['Dropdown', 'phosphor-caret-up-down'],
        'radio' => ['Single choice', 'phosphor-stop-circle'],
        'checkboxes' => ['Multiple choice', 'phosphor-check-circle'],
        'date' => ['Date', 'phosphor-calendar-dots'],
        'file' => ['File upload', 'phosphor-paperclip'],
        'consent' => ['Consent checkbox', 'phosphor-shield-check'],
    ];

    /**
     * @return array<int, Block>
     */
    public static function builderBlocks(): array
    {
        return collect(self::TYPES)->map(fn (array $type, string $name): Block => Block::make($name)
            ->label(fn (?array $state): string => filled($state['label'] ?? null) ? $state['label'].' · '.$type[0] : $type[0])
            ->icon($type[1])
            ->columns(2)
            ->schema(static::schemaFor($name)))
            ->values()
            ->all();
    }

    /**
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

        $specific = match ($type) {
            'select', 'radio', 'checkboxes' => [
                TagsInput::make('options')->label('Choices')->placeholder('Add a choice, press Enter')->required()->columnSpanFull(),
            ],
            'file' => [
                TextInput::make('accept')->label('Allowed types')->placeholder('pdf, jpg, png')->helperText('File extensions, comma separated.'),
                TextInput::make('max_mb')->label('Max size (MB)')->numeric()->minValue(1)->maxValue(20)->default(10),
            ],
            'consent' => [
                Textarea::make('text')->label('Text next to the box')->placeholder('I agree that you store my details to answer my request.')->rows(2)->columnSpanFull(),
            ],
            default => [
                TextInput::make('placeholder')->maxLength(255)->withAi(),
            ],
        };

        return [
            ...$common,
            ...$specific,
            Grid::make(3)->columnSpanFull()->schema([
                TextInput::make('help')->label('Help text')->columnSpan(2)->withAi(),
                ToggleButtons::make('width')->options(['full' => 'Full', 'half' => 'Half'])->default('full')->inline()->grouped(),
            ]),
            Toggle::make('required')->label('Required')->default($type === 'consent'),
        ];
    }

    /**
     * Inputs for a form's field text in another language, one group per field, with the
     * default site language's text as placeholders. Stored as field_translations.{locale}.
     *
     * @return array<int, mixed>
     */
    public static function translationSchema(Form $form, string $locale): array
    {
        return collect($form->definitions(ContentLocale::default()))->map(function (array $field) use ($locale): Fieldset {
            $path = "field_translations.{$locale}.{$field['name']}";
            $data = $field['data'];

            return Fieldset::make($field['label'])
                ->columns(1)
                ->schema(array_values(array_filter([
                    static::translatedWithAi(TextInput::make("{$path}.label")->label(__('Label'))->placeholder($field['label'])->maxLength(255), $field['label'], $locale),
                    match ($field['type']) {
                        'select', 'radio', 'checkboxes' => TagsInput::make("{$path}.options")
                            ->label(__('Choices'))
                            ->placeholder(implode(', ', (array) ($data['options'] ?? [])))
                            ->helperText(__('The same choices, in the same order.')),
                        'consent' => static::translatedWithAi(Textarea::make("{$path}.text")->label(__('Text next to the box'))->placeholder($data['text'] ?? '')->rows(2), $data['text'] ?? null, $locale),
                        'file' => null,
                        // Only text the field has in the default language needs translating.
                        default => filled($data['placeholder'] ?? null) ? static::translatedWithAi(TextInput::make("{$path}.placeholder")->label(__('Placeholder'))->placeholder($data['placeholder']), $data['placeholder'], $locale) : null,
                    },
                    in_array($field['type'], ['select', 'radio', 'checkboxes', 'consent'], true) || blank($data['help'] ?? null)
                        ? null
                        : static::translatedWithAi(TextInput::make("{$path}.help")->label(__('Help text'))->placeholder($data['help']), $data['help'], $locale),
                ])));
        })->all();
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
     * Laravel validation rules for one field definition.
     *
     * @param  array{type: string, required: bool, data: array<string, mixed>}  $field
     * @return array<int, mixed>
     */
    public static function rules(array $field): array
    {
        $required = $field['required'] ? 'required' : 'nullable';
        $options = array_values(array_filter((array) ($field['data']['options'] ?? []), 'is_string'));

        return match ($field['type']) {
            'email' => [$required, 'email:rfc', 'max:255'],
            'phone' => [$required, 'string', 'max:40', 'regex:/^[0-9+()\\s.-]+$/'],
            'textarea' => [$required, 'string', 'max:5000'],
            'select', 'radio' => [$required, 'string', Rule::in($options)],
            'checkboxes' => [$field['required'] ? 'required' : 'nullable', 'array', 'list', function (string $attribute, mixed $value, \Closure $fail) use ($options): void {
                if (array_filter((array) $value, fn (mixed $choice): bool => ! is_string($choice)) !== [] || array_diff((array) $value, $options) !== []) {
                    $fail('Choose from the options given.');
                }
            }],
            'date' => [$required, 'date'],
            'file' => [$required, 'file', 'max:'.(int) (($field['data']['max_mb'] ?? 10) * 1024), 'mimes:'.implode(',', static::acceptedExtensions($field['data']['accept'] ?? null))],
            'consent' => $field['required'] ? ['accepted'] : ['nullable'],
            default => [$required, 'string', 'max:255'],
        };
    }
}
