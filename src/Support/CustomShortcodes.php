<?php

namespace Aurora\Support;

use Aurora\Models\Setting;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\Shortcodes;

/**
 * Shortcodes written in the admin (Settings › Website › Shortcodes): a name editors type in
 * any text ([phone]) and the text it stands for, the same on every site. Registered as the
 * app boots, each reading its text when it's drawn, so a change shows straight away. Their
 * text is printed as text, never as HTML. Built-in shortcodes keep their names.
 */
class CustomShortcodes
{
    public const SETTING = 'shortcodes';

    /**
     * The panel whose block registry holds the shortcodes (Aurora\Blocks\Blocks).
     */
    protected const PANEL = 'admin';

    /**
     * The names registered from the setting: re-registering one takes its new text.
     *
     * @var array<int, string>
     */
    protected static array $registered = [];

    /**
     * @return array<int, array{name: string, text: string, description: ?string}>
     */
    public static function all(): array
    {
        return collect(Setting::get(self::SETTING) ?? [])
            ->filter(fn (mixed $code): bool => is_array($code) && is_string($code['name'] ?? null) && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $code['name']))
            ->map(fn (array $code): array => ['name' => $code['name'], 'text' => (string) ($code['text'] ?? ''), 'description' => filled($code['description'] ?? null) ? (string) $code['description'] : null])
            ->values()
            ->all();
    }

    /**
     * Into the page builder's shortcodes, those whose names aren't taken.
     */
    public static function register(): void
    {
        $codes = static::shortcodes();

        foreach (static::all() as $code) {
            if ($codes->has($code['name']) && ! in_array($code['name'], static::registered(), true)) {
                continue;
            }

            $codes->register(
                $code['name'],
                fn (): string => nl2br(e(static::text($code['name'])), false),
                $code['description'] ?? fn (): string => __('Text from Settings › Shortcodes.'),
                '['.$code['name'].']',
            );

            static::$registered[] = $code['name'];
        }
    }

    /**
     * The text a shortcode stands for now.
     */
    public static function text(string $name): string
    {
        return collect(static::all())->firstWhere('name', $name)['text'] ?? '';
    }

    /**
     * Names no custom shortcode may take: the built-in ones and those from code.
     *
     * @return array<int, string>
     */
    public static function reserved(): array
    {
        return array_values(array_diff(array_keys(static::shortcodes()->all()), static::registered()));
    }

    /**
     * @return array<int, string>
     */
    protected static function registered(): array
    {
        return static::$registered;
    }

    protected static function shortcodes(): Shortcodes
    {
        return app(BlockRegistries::class)->for(self::PANEL)->shortcodes();
    }
}
