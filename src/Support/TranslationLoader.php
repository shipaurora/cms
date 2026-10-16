<?php

namespace Aurora\Support;

use Aurora\Models\LanguageLine;
use Illuminate\Support\Arr;
use Illuminate\Translation\FileLoader;
use Spatie\TranslationLoader\TranslationLoaderManager;

/**
 * Laravel's translation loader with the database on top, for every label: the app's own
 * ("*" and "group.key") and the packages' ("filament-panels::layout.…", stored with the
 * group "filament-panels::layout"). The lang files stay the base layer; what editors write
 * in Administration → Translations overrides them, per locale.
 */
class TranslationLoader extends TranslationLoaderManager
{
    public function load($locale, $group, $namespace = null): array
    {
        if ($namespace === null || $namespace === '*') {
            return parent::load($locale, $group, $namespace);
        }

        $files = $this->fileTranslations($locale, $group, $namespace);

        try {
            return array_replace_recursive($files, LanguageLine::getTranslationsForGroup($locale, $namespace.'::'.$group));
        } catch (\Throwable) {
            return $files;
        }
    }

    /**
     * What the lang files alone say: the text a label has before anyone edits it.
     *
     * @return array<string, mixed>
     */
    public function fileTranslations(string $locale, string $group, ?string $namespace = null): array
    {
        return FileLoader::load($locale, $group, $namespace);
    }

    /**
     * One label's text from the lang files, or null when they don't have it in that locale.
     */
    public function fileText(string $locale, string $group, string $key): ?string
    {
        [$namespace, $group] = str_contains($group, '::') ? explode('::', $group, 2) : ['*', $group];

        $value = $group === '*'
            ? ($this->fileTranslations($locale, '*', '*')[$key] ?? null)
            : Arr::get($this->fileTranslations($locale, $group, $namespace), $key);

        return is_string($value) ? $value : null;
    }
}
