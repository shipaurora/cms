<?php

namespace Aurora\Forms\Fields;

use Closure;

/**
 * Any number of the choices, kept as a list.
 */
class Checkboxes extends Choice
{
    public static function type(): string
    {
        return 'checkboxes';
    }

    public static function label(): string
    {
        return __('Multiple choice');
    }

    public static function icon(): string
    {
        return 'phosphor-check-circle';
    }

    public static function rules(array $field): array
    {
        $options = static::options($field);

        return [$field['required'] ? 'required' : 'nullable', 'array', 'list', function (string $attribute, mixed $value, Closure $fail) use ($options): void {
            if (array_filter((array) $value, fn (mixed $choice): bool => ! is_string($choice)) !== [] || array_diff((array) $value, $options) !== []) {
                $fail('Choose from the options given.');
            }
        }];
    }
}
