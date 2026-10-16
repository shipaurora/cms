<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\Locale;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;

/**
 * A field written per language in a dialog, however many languages there are: a language
 * picker (like the edit pages' language switcher) and the field in the chosen language. Every
 * language's text is kept ({field}.{code}). The default language comes first and keeps the
 * field's own rules; the others are optional.
 */
class TranslatedField
{
    /**
     * The picker with the field under it.
     *
     * @param  array<int, string>  $locales  language codes, the default first
     * @param  (Closure(string $locale): Closure)|null  $placeholder  each language's placeholder
     */
    public static function make(Field $field, array $locales, ?Closure $placeholder = null): Group
    {
        ['picker' => $picker, 'fields' => $fields] = static::parts($field, $locales, $placeholder);

        return Group::make([$picker, $fields]);
    }

    /**
     * The picker and the field apart, for a form that puts other fields between them.
     *
     * @param  array<int, string>  $locales  language codes, the default first
     * @param  (Closure(string $locale): Closure)|null  $placeholder  each language's placeholder
     * @return array{picker: Select, fields: Group}
     */
    public static function parts(Field $field, array $locales, ?Closure $placeholder = null): array
    {
        $name = $field->getName();
        $picker = static::pickerName($name);
        $names = Locale::options();

        return [
            'picker' => Select::make($picker)
                ->hiddenLabel()
                ->prefixIcon('phosphor-translate')
                ->options(array_combine($locales, array_map(fn (string $code): string => $names[$code] ?? strtoupper($code), $locales)))
                ->default($locales[0])
                ->selectablePlaceholder(false)
                ->live()
                ->dehydrated(false),
            'fields' => Group::make(array_map(function (string $code) use ($field, $name, $picker, $locales, $placeholder): Field {
                $translated = (clone $field)
                    ->name("{$name}.{$code}")
                    ->statePath("{$name}.{$code}")
                    ->hidden(fn (Get $get): bool => ($get($picker) ?? $locales[0]) !== $code)
                    ->dehydratedWhenHidden();

                if ($code !== $locales[0]) {
                    $translated->required(false);
                }

                if ($placeholder !== null && method_exists($translated, 'placeholder')) {
                    $translated->placeholder($placeholder($code));
                }

                return $translated;
            }, $locales)),
        ];
    }

    public static function pickerName(string $field): string
    {
        return $field.'_language';
    }
}
