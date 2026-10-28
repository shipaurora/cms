<?php

namespace Aurora\Forms\Fields;

use Aurora\Forms\FieldType;
use Filament\Forms\Components\TagsInput;
use Illuminate\Validation\Rule;

/**
 * A field answered from a list of choices the editor writes: a dropdown, single choice, or
 * (Checkboxes) several. The answer has to be one of them.
 */
abstract class Choice extends FieldType
{
    public static function settings(): array
    {
        return [
            TagsInput::make('options')->label(__('Choices'))->placeholder(__('Add a choice, press Enter'))->required()->columnSpanFull(),
        ];
    }

    public static function translatable(): array
    {
        return ['options' => 'options'];
    }

    public static function rules(array $field): array
    {
        return [$field['required'] ? 'required' : 'nullable', 'string', Rule::in(static::options($field))];
    }

    public static function publicData(): array
    {
        return [...parent::publicData(), 'options'];
    }

    /**
     * @param  array{data: array<string, mixed>}  $field
     * @return array<int, string>
     */
    public static function options(array $field): array
    {
        return array_values(array_filter((array) ($field['data']['options'] ?? []), 'is_string'));
    }
}
