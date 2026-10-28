<?php

namespace Aurora\Support;

use Aurora\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * Each person's own dashboard: the widgets it shows, in what order and how wide. The same widget
 * can be on it more than once, so each one on the dashboard has an id of its own. Saved on the
 * person (users.dashboard) as those widgets and the widgets that existed when they saved
 * ("known"): a widget added since (in an update, or by the website) shows at the end, so nobody
 * misses something new, while one they removed stays removed. Widgets a person may not see are
 * never shown, whatever their layout says.
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
     * The widgets on the person's dashboard, in order, each with its id and size.
     *
     * @return array<int, array{id: string, widget: class-string<Widget>, size: string}>
     */
    public static function for(?User $user): array
    {
        $saved = $user?->dashboard;

        if (! is_array($saved) || ! is_array($saved['widgets'] ?? null)) {
            return static::default();
        }

        $items = static::clean($saved['widgets']);

        // Before 1.3.9 a layout listed the hidden widgets instead: those and the shown ones were known.
        $known = $saved['known'] ?? [...array_column($items, 'widget'), ...($saved['hidden'] ?? [])];

        foreach (static::available() as $widget) {
            if (! in_array($widget, $known, true)) {
                $items[] = static::item($widget);
            }
        }

        return $items;
    }

    /**
     * Save the person's dashboard from its widgets, in order, with their size. Widgets they may
     * not see, and sizes that don't exist, are left out or put right.
     *
     * @param  array<int, array{id?: string, widget: string, size?: string}>  $widgets
     */
    public static function save(User $user, array $widgets): void
    {
        $user->forceFill(['dashboard' => [
            'widgets' => static::clean($widgets),
            'known' => static::available(),
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
     * The default layout, as the panel orders and sizes its widgets, each once.
     *
     * @return array<int, array{id: string, widget: class-string<Widget>, size: string}>
     */
    public static function default(): array
    {
        return array_map(fn (string $widget): array => static::item($widget, Str::kebab(class_basename($widget))), static::available());
    }

    /**
     * A widget to put on the dashboard, with a new id unless one is given, at its default size.
     *
     * @return array{id: string, widget: class-string<Widget>, size: string}
     */
    public static function item(string $widget, ?string $id = null): array
    {
        return ['id' => $id ?? Str::lower(Str::random(10)), 'widget' => $widget, 'size' => static::defaultSize($widget)];
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

    /**
     * The widgets the person may see, each with a usable id (unique, plain) and a size that exists.
     *
     * @param  array<mixed>  $widgets
     * @return array<int, array{id: string, widget: class-string<Widget>, size: string}>
     */
    protected static function clean(array $widgets): array
    {
        $available = static::available();
        $ids = [];
        $items = [];

        foreach ($widgets as $item) {
            if (! is_array($item) || ! in_array($item['widget'] ?? null, $available, true)) {
                continue;
            }

            $id = $item['id'] ?? null;
            $id = is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id) && ! isset($ids[$id]) ? $id : static::item($item['widget'])['id'];
            $ids[$id] = true;

            $size = $item['size'] ?? null;

            $items[] = [
                'id' => $id,
                'widget' => $item['widget'],
                'size' => is_string($size) && array_key_exists($size, self::SIZES) ? $size : static::defaultSize($item['widget']),
            ];
        }

        return $items;
    }
}
