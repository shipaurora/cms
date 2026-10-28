<?php

namespace Aurora\Forms\Fields;

use Aurora\Forms\FieldType;
use Filament\Forms\Components\Textarea;
use Illuminate\Http\Request;

/**
 * A box to tick to agree, with the text beside it; kept as Yes or No.
 */
class Consent extends FieldType
{
    public static function type(): string
    {
        return 'consent';
    }

    public static function label(): string
    {
        return __('Consent checkbox');
    }

    public static function icon(): string
    {
        return 'phosphor-shield-check';
    }

    public static function settings(): array
    {
        return [
            Textarea::make('text')->label(__('Text next to the box'))->placeholder(__('I agree that you store my details to answer my request.'))->rows(2)->columnSpanFull(),
        ];
    }

    public static function requiredByDefault(): bool
    {
        return true;
    }

    public static function translatable(): array
    {
        return ['text' => 'textarea'];
    }

    public static function rules(array $field): array
    {
        return $field['required'] ? ['accepted'] : ['nullable'];
    }

    public static function value(array $field, Request $request): string
    {
        return $request->boolean($field['name']) ? 'Yes' : 'No';
    }

    public static function publicData(): array
    {
        return [...parent::publicData(), 'text'];
    }
}
