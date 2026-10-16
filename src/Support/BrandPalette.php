<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The site's brand and accent colours from Site settings, as CSS custom properties.
 *
 * Every block paints with `brand-*` and `accent-*` utilities, which Tailwind compiles to
 * these variables. Overriding them at runtime re-themes the public site and the visual
 * editor at once, without a rebuild.
 */
class BrandPalette
{
    public static function css(): string
    {
        $brand = Setting::get('brand_color');
        $accent = Setting::get('accent_color');

        if (blank($brand) && blank($accent)) {
            return '';
        }

        return Cache::rememberForever('brand-palette:'.md5($brand.'|'.$accent), function () use ($brand, $accent): string {
            $declarations = [
                ...static::declarations('brand', $brand),
                ...static::declarations('accent', $accent),
            ];

            return $declarations === [] ? '' : ':root,.fpb-canvas{'.implode(';', $declarations).'}';
        });
    }

    /**
     * @return array<int, string>
     */
    protected static function declarations(string $name, ?string $hex): array
    {
        if (! is_string($hex) || ! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            return [];
        }

        try {
            $palette = Color::generatePalette($hex);
        } catch (Throwable) {
            return [];
        }

        return collect($palette)
            ->map(fn (string $value, int|string $shade): string => "--color-{$name}-{$shade}:{$value}")
            ->values()
            ->all();
    }
}
