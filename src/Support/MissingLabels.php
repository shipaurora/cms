<?php

namespace Aurora\Support;

use Aurora\Models\LanguageLine;
use Aurora\Models\Locale;

/**
 * What to show for a label the current language has no text for.
 *
 * Wired to Translator::handleMissingKeysUsing(). Laravel falls back to the default language
 * for "group.key" labels, but not for sentence labels ("New page"), which it shows as the
 * key itself. Here they show the default language's text instead, so an edit made to the
 * English wording also shows in languages that haven't translated it yet.
 */
class MissingLabels
{
    public static function handle(string $key, array $replace, ?string $locale, bool $fallback): string
    {
        $default = Locale::defaultCode();

        if ($fallback && $locale !== $default && ! str_contains($key, '::')) {
            $text = rescue(fn () => LanguageLine::getTranslationsForGroup($default, '*')[$key] ?? null, report: false);

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return $key;
    }
}
