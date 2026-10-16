<?php

namespace Aurora\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * A resource's own labels (sidebar entry, record names, breadcrumb) in the person's
 * language. Every name is worked out in English first, then translated as a whole: Filament
 * would otherwise pluralise the already translated name with English rules ("skjemas").
 * The English text is the translation key, in lang/*.json.
 */
trait TranslatesResourceLabels
{
    public static function getModelLabel(): string
    {
        return __(static::englishModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(static::englishPluralModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }

    public static function getNavigationLabel(): string
    {
        return filled(static::$navigationLabel) ? __(static::$navigationLabel) : static::getTitleCasePluralModelLabel();
    }

    public static function getBreadcrumb(): string
    {
        return filled(static::$breadcrumb) ? __(static::$breadcrumb) : static::getTitleCasePluralModelLabel();
    }

    protected static function englishModelLabel(): string
    {
        return parent::getModelLabel();
    }

    protected static function englishPluralModelLabel(): string
    {
        return static::$pluralModelLabel ?? static::getPluralLabel() ?? Str::plural(static::englishModelLabel());
    }
}
