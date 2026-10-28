<?php

namespace Aurora\Support;

use Aurora\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * Each person's own dashboard: which widgets it shows, in what order and how wide. Saved on
 * the person (users.dashboard) as the widgets shown, in order, with their size, and the ones
 * hidden. A widget in neither (one added in an update, or by the website) is shown at the end,
 * so nobody misses something new. Widgets a person may not see are never shown, whatever
 * their layout says.
 */
class DashboardLayout
{
    /**
     * How wide a widget is: columns of the dashboard's three (two on a tablet, one on a phone).
     *
     * @var array<string, int>
     */
    public const SIZES = ['small' => 1, 'medium' => 2, 'large' => 3];

    /**
     * Every dashboard widget the person may see, in the default order.
     *
     * @return array<int, class-string<Widget>>
     */
    public static function available(): array
    {
        return collect(Filament::getWidgets())
            ->map(fn (string|WidgetConfiguration $widget): string => $widget instanceof WidgetConfiguration ? $widget->widget : $widget)
            ->filter(fn (string $widget): bool => $widget::canView())
            ->values()
            ->all();
    }

    /**
     * The widgets the person's dashboard shows, in order, with their size.
     *
     * @return array<int, array{widget: class-string<Widget>, size: string}>
     */
    public static function for(?User $user): array
    {
        $available = static::available();
        $saved = $user?->dashboard ?? [];
        $hidden = $saved['hidden'] ?? [];

        $shown = collect($saved['widgets'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['widget'] ?? null, $available, true))
            ->map(fn (array $item): array => ['widget' => $item['widget'], 'size' => static::size($item['size'] ?? null, $item['widget'])])
            ->unique('widget')
            ->values();

        // New widgets, in neither list, at the end.
        $new = collect($available)
            ->reject(fn (string $widget): bool => $shown->contains('widget', $widget) || in_array($widget, $hidden, true))
            ->map(fn (string $widget): array => ['widget' => $widget, 'size' => static::defaultSize($widget)]);

        return $shown->concat($new)->values()->all();
    }

    /**
     * The widgets the person hid, which they can add back.
     *
     * @return array<int, class-string<Widget>>
     */
    public static function hidden(?User $user): array
    {
        $shown = array_column(static::for($user), 'widget');

        return array_values(array_diff(static::available(), $shown));
    }

    /**
     * Save the person's dashboard from the widgets to show, in order, with their size. Widgets
     * they may not see, and sizes that don't exist, are left out.
     *
     * @param  array<int, array{widget: string, size?: string}>  $widgets
     */
    public static function save(User $user, array $widgets): void
    {
        $available = static::available();

        $shown = collect($widgets)
            ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['widget'] ?? null, $available, true))
            ->map(fn (array $item): array => ['widget' => $item['widget'], 'size' => static::size($item['size'] ?? null, $item['widget'])])
            ->unique('widget')
            ->values();

        $user->forceFill(['dashboard' => [
            'widgets' => $shown->all(),
            'hidden' => array_values(array_diff($available, $shown->pluck('widget')->all())),
        ]])->save();
    }

    /**
     * Back to the default dashboard.
     */
    public static function reset(User $user): void
    {
        $user->forceFill(['dashboard' => null])->save();
    }

    /**
     * The default layout, as the panel orders and sizes its widgets.
     *
     * @return array<int, array{widget: class-string<Widget>, size: string}>
     */
    public static function default(): array
    {
        return array_map(fn (string $widget): array => ['widget' => $widget, 'size' => static::defaultSize($widget)], static::available());
    }

    /**
     * A widget's name in the customise view: its own (getDashboardLabel()), else its class name.
     */
    public static function label(string $widget): string
    {
        return method_exists($widget, 'getDashboardLabel') ? $widget::getDashboardLabel() : Str::headline(class_basename($widget));
    }

    /**
     * What a widget shows, in a sentence, when it says (getDashboardDescription()).
     */
    public static function description(string $widget): ?string
    {
        return method_exists($widget, 'getDashboardDescription') ? $widget::getDashboardDescription() : null;
    }

    /**
     * A size that exists, else the widget's default.
     */
    protected static function size(mixed $size, string $widget): string
    {
        return is_string($size) && array_key_exists($size, self::SIZES) ? $size : static::defaultSize($widget);
    }

    /**
     * The size closest to the widget's own column span on a wide screen.
     */
    public static function defaultSize(string $widget): string
    {
        $span = (new ReflectionProperty($widget, 'columnSpan'))->getDefaultValue();

        if (is_array($span)) {
            $span = $span['xl'] ?? $span['lg'] ?? $span['md'] ?? $span['default'] ?? 1;
        }

        return match (true) {
            $span === 'full', is_int($span) && $span >= 3 => 'large',
            $span === 2 => 'medium',
            default => 'small',
        };
    }
}
