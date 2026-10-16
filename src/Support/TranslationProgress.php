<?php

namespace Aurora\Support;

use Aurora\Models\LanguageLine;
use Illuminate\Support\Arr;

/**
 * How much of the CMS reads in a language: labels with a translation in the database or
 * the lang files, counted per group so each lang file is read once.
 */
class TranslationProgress
{
    public static function percentage(string $locale): int
    {
        // Once per request: the table and the tabs ask for the same language more than once.
        return once(fn (): int => static::calculate($locale));
    }

    protected static function calculate(string $locale): int
    {
        $lines = LanguageLine::query()->get(['group', 'key', 'text']);

        if ($lines->isEmpty()) {
            return 100;
        }

        $loader = app('translator')->getLoader();
        $translated = 0;

        foreach ($lines->groupBy('group') as $group => $groupLines) {
            [$namespace, $name] = str_contains($group, '::') ? explode('::', $group, 2) : ['*', $group];
            $files = $loader instanceof TranslationLoader ? $loader->fileTranslations($locale, $name, $namespace) : [];

            foreach ($groupLines as $line) {
                $fromFiles = $group === '*' ? ($files[$line->key] ?? null) : Arr::get($files, $line->key);

                if (filled($line->text[$locale] ?? null) || is_string($fromFiles)) {
                    $translated++;
                }
            }
        }

        return (int) floor($translated / $lines->count() * 100);
    }
}
