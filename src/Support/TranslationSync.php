<?php

namespace Aurora\Support;

use Aurora\Aurora;
use Aurora\Models\LanguageLine;
use Aurora\Models\Locale;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Keeps the lang files and the database in step. The lang files are the source of every
 * label; the database is what the site and admin read (via Aurora\Support\TranslationLoader),
 * and what editors change in Administration → Translations.
 *
 * - scan(): finds labels in the code (__() and friends, Filament ->label('…') and the labels
 *   Filament makes from field and column names) and adds the new ones to lang/{default}.json.
 * - import(): copies every label from the lang files into the database, for every language
 *   in use: the app's own (lang/*.json, lang/{locale}/*.php), Laravel's, and every package's
 *   (with lang/vendor overrides). A label edited in the admin keeps its edit; an unedited
 *   one follows the files.
 * - export(): writes the admin's edits back into the lang files, so they can be committed.
 */
class TranslationSync
{
    /**
     * Where to look for labels: the app's code, and that of every Aurora package installed
     * (the CMS, its block library …).
     *
     * @return array<int, string>
     */
    protected function paths(): array
    {
        $packages = collect(InstalledVersions::getInstalledPackages())
            ->filter(fn (string $name): bool => str_starts_with($name, 'aurora/'))
            ->map(fn (string $name): ?string => ($path = InstalledVersions::getInstallPath($name)) ? (realpath($path) ?: null) : null)
            ->push(dirname(__DIR__, 2))
            ->filter()
            ->unique()
            ->flatMap(fn (string $package): array => [$package.'/src', $package.'/resources/views']);

        return array_values(array_filter([app_path(), resource_path('views'), ...$packages], is_dir(...)));
    }

    /**
     * Filament classes whose make('name') gets a label made from the name.
     */
    protected const NAMED = 'TextInput|Textarea|Select|Toggle|Checkbox|CheckboxList|Radio|ToggleButtons|DatePicker|DateTimePicker|TimePicker|ColorPicker|FileUpload|RichEditor|MarkdownEditor|TagsInput|KeyValue|Repeater|Builder|Slider|Hidden|TextColumn|IconColumn|ImageColumn|ToggleColumn|TextInputColumn|SelectColumn|ColorColumn|ViewColumn|TextEntry|IconEntry|ImageEntry|SelectFilter|TernaryFilter|Filter|TrashedFilter|AssetPicker|FolderSelect';

    /**
     * New labels from the code, added to lang/{default}.json (as their own English text).
     *
     * @return array{found: int, added: int}
     */
    public function scan(): array
    {
        $file = Aurora::translationsPath(Locale::defaultCode().'.json');
        $existing = File::exists($file) ? (json_decode(File::get($file), true) ?: []) : [];
        $found = array_values(array_filter(array_unique([...$this->keysInCode(), ...$this->panelLabels()]), fn (string $key): bool => $this->isSentenceKey($key)));
        $new = array_diff($found, array_keys($existing));

        if ($new !== []) {
            foreach ($new as $key) {
                $existing[$key] = $key;
            }

            ksort($existing, SORT_NATURAL | SORT_FLAG_CASE);
            File::put($file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        return ['found' => count($found), 'added' => count($new)];
    }

    /**
     * Copy the lang files into the database.
     *
     * @return array{found: int, added: int, updated: int, unused: int}
     */
    public function import(): array
    {
        $now = now();
        $locales = array_keys(Locale::options()) ?: [config('app.fallback_locale')];
        $lines = LanguageLine::query()->get()->keyBy(fn (LanguageLine $line): string => $line->group."\0".$line->key);
        $added = 0;
        $updated = 0;
        $found = 0;

        foreach ($this->sources($locales) as $group => $keys) {
            foreach ($keys as $key => $texts) {
                $found++;
                $line = $lines[$group."\0".$key] ?? null;

                if ($line === null) {
                    LanguageLine::query()->insert([
                        'id' => (string) Str::uuid7(),
                        'group' => $group,
                        'key' => $key,
                        'text' => json_encode((object) $texts, JSON_UNESCAPED_UNICODE),
                        'imported' => json_encode((object) $texts, JSON_UNESCAPED_UNICODE),
                        'last_seen_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $added++;

                    continue;
                }

                // Unedited texts (still what was imported) follow the files; edits stay.
                $text = $line->text ?? [];
                $imported = $line->imported ?? [];

                foreach ($texts as $locale => $value) {
                    if (! isset($text[$locale]) || ($text[$locale] === ($imported[$locale] ?? null))) {
                        $text[$locale] = $value;
                    }
                }

                // Per-locale texts compared whatever order the locales come in.
                $sorted = function (array $values): array {
                    ksort($values);

                    return $values;
                };
                $changed = $sorted($text) !== $sorted($line->text ?? []) || $sorted($texts) !== $sorted($imported);
                $line->forceFill(['text' => $text, 'imported' => $texts, 'last_seen_at' => $now]);

                if ($changed) {
                    $line->flushGroupCache();
                    $line->save();
                    $updated++;
                } else {
                    $line->saveQuietly();
                }
            }
        }

        LanguageLine::query()->select('group')->distinct()->pluck('group')
            ->each(fn (string $group) => collect($locales)->each(fn (string $locale) => cache()->forget(LanguageLine::getCacheKey($group, $locale))));

        return [
            'found' => $found,
            'added' => $added,
            'updated' => $updated,
            'unused' => LanguageLine::query()->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now))->count(),
        ];
    }

    /**
     * Write edits made in the admin into the lang files: the app's own labels into
     * lang/{locale}.json and lang/{locale}/{group}.php, packages' into lang/vendor.
     *
     * @return array{labels: int, files: int}
     */
    public function export(): array
    {
        $files = [];
        $labels = 0;

        foreach (LanguageLine::query()->get() as $line) {
            foreach ($line->editedLocales() as $locale) {
                $value = $line->text[$locale];
                $labels++;

                if ($line->group === '*') {
                    $files[Aurora::translationsPath($locale.'.json')]['json'][$line->key] = $value;
                } elseif (str_contains($line->group, '::')) {
                    [$namespace, $group] = explode('::', $line->group, 2);
                    Arr::set($files[Aurora::translationsPath("vendor/{$namespace}/{$locale}/{$group}.php")]['php'], $line->key, $value);
                } else {
                    Arr::set($files[Aurora::translationsPath("{$locale}/{$line->group}.php")]['php'], $line->key, $value);
                }
            }
        }

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($path));

            if (isset($content['json'])) {
                $merged = [...(File::exists($path) ? (json_decode(File::get($path), true) ?: []) : []), ...$content['json']];
                ksort($merged, SORT_NATURAL | SORT_FLAG_CASE);
                File::put($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            } else {
                $merged = array_replace_recursive(File::exists($path) ? (array) require $path : [], $content['php']);
                File::put($path, "<?php\n\nreturn ".$this->exportArray($merged).";\n");
            }
        }

        return ['labels' => $labels, 'files' => count($files)];
    }

    /**
     * Every label with its text per locale, from the lang files: group => key => texts.
     *
     * @param  array<int, string>  $locales
     * @return array<string, array<string, array<string, string>>>
     */
    public function sources(array $locales): array
    {
        $loader = app('translator')->getLoader();
        $groups = [];

        // The app's sentence labels: lang/{locale}.json (the files alone, not the database).
        foreach ($locales as $locale) {
            $json = $loader instanceof TranslationLoader ? $loader->fileTranslations($locale, '*', '*') : $loader->load($locale, '*', '*');

            foreach ($json as $key => $value) {
                if (is_string($value) && mb_strlen($key) <= 500) {
                    $groups['*'][$key][$locale] = $value;
                }
            }
        }

        // The app's and Laravel's "group.key" labels, then every package's.
        $named = [
            ...array_fill_keys($this->groupsIn([...Aurora::langPaths(), Aurora::packagePath('laravel/framework', 'src/Illuminate/Translation/lang')]), '*'),
        ];

        foreach ($loader->namespaces() as $namespace => $path) {
            foreach ($this->groupsIn([$path, ...array_map(fn (string $lang): string => "{$lang}/vendor/{$namespace}", Aurora::langPaths())]) as $group) {
                $named[$namespace.'::'.$group] = $namespace;
            }
        }

        foreach ($named as $fullGroup => $namespace) {
            $group = $namespace === '*' ? $fullGroup : Str::after($fullGroup, '::');

            foreach ($locales as $locale) {
                $values = $loader instanceof TranslationLoader
                    ? $loader->fileTranslations($locale, $group, $namespace)
                    : [];

                foreach (Arr::dot($values) as $key => $value) {
                    if (is_string($value)) {
                        $groups[$fullGroup][$key][$locale] = $value;
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * @return array<int, string>
     */
    public function keysInCode(): array
    {
        $translated = '/(?:__|trans|trans_choice|@lang|Lang::get)\(\s*([\'"])((?:\\\\.|(?!\1).)+)\1/s';
        $labelled = '/->label\(\s*\'((?:[^\'\\\\]|\\\\.)+)\'\s*\)/';
        $named = '/\b(?:'.self::NAMED.')::make\(\s*\'([A-Za-z0-9_.]+)\'/';
        $actions = '/\b(?:Action|BulkAction)::make\(\s*\'([A-Za-z0-9_]+)\'/';
        $keys = ['Actions', 'This page'];

        foreach ($this->paths() as $path) {
            foreach (File::allFiles($path) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $source = $file->getContents();

                preg_match_all($translated, $source, $matches);
                array_push($keys, ...array_map('stripcslashes', $matches[2]));

                preg_match_all($labelled, $source, $matches);
                array_push($keys, ...array_map('stripcslashes', $matches[1]));

                preg_match_all($named, $source, $matches);
                array_push($keys, ...array_map(fn (string $name): string => (string) str($name)->afterLast('.')->kebab()->replace(['-', '_'], ' ')->ucfirst(), $matches[1]));

                // Command palette entries: label and description (Aurora\Support\PaletteCommands).
                preg_match_all('/static::(?:item|assistant)\(\s*\'((?:[^\'\\\\]|\\\\.)+)\',\s*\'((?:[^\'\\\\]|\\\\.)+)\'/', $source, $matches);
                array_push($keys, ...array_map('stripcslashes', $matches[1]), ...array_map('stripcslashes', $matches[2]));

                preg_match_all($actions, $source, $matches);
                array_push($keys, ...array_map(fn (string $name): string => (string) str($name)->kebab()->replace(['-', '_'], ' ')->ucfirst(), $matches[1]));
            }
        }

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => ! str_contains($key, '::') && ! str_contains($key, '$') && ! str_contains($key, '{{') && trim($key) !== '')));
    }

    /**
     * Labels Filament builds itself, without any string in our code to scan: resources'
     * names (sidebar entry, singular, plural, breadcrumb) and custom pages' titles. Read in
     * the default language with no translations loaded, so they come back as written.
     *
     * @return array<int, string>
     */
    public function panelLabels(): array
    {
        $labels = [];
        $translator = app('translator');
        $previous = $translator->getLocale();
        $translator->setLocale('__source');

        try {
            foreach (Filament::getPanels() as $panel) {
                foreach ($panel->getResources() as $resource) {
                    array_push($labels, ...array_map(fn (callable $read): ?string => rescue($read, null, report: false), [
                        fn () => $resource::getNavigationLabel(),
                        fn () => $resource::getModelLabel(),
                        fn () => $resource::getPluralModelLabel(),
                        fn () => Str::ucfirst($resource::getModelLabel()),
                        fn () => Str::ucfirst($resource::getPluralModelLabel()),
                        fn () => $resource::getBreadcrumb(),
                    ]));
                }

                foreach ($panel->getPages() as $page) {
                    $labels[] = rescue(fn () => $page::getNavigationLabel(), null, report: false);
                    $labels[] = rescue(fn () => (string) app($page)->getTitle(), null, report: false);
                }
            }
        } finally {
            $translator->setLocale($previous);
        }

        return array_values(array_filter(array_unique($labels), fn (?string $label): bool => filled($label) && $label !== '__source'));
    }

    /**
     * A plain-sentence label, as opposed to a "group.key" one from a lang file.
     */
    protected function isSentenceKey(string $key): bool
    {
        if (preg_match('/^([a-z0-9_-]+)\.[a-z0-9_.-]+$/i', $key, $parts)) {
            return collect(Aurora::langPaths())->every(fn (string $lang): bool => File::glob($lang.'/*/'.$parts[1].'.php') === [])
                && ! in_array($parts[1], ['auth', 'pagination', 'passwords', 'validation'], true);
        }

        return mb_strlen($key) <= 500 && preg_match('/\p{L}/u', $key) === 1;
    }

    /**
     * The groups (file names) in {path}/{locale}/*.php for the given lang directories.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function groupsIn(array $paths): array
    {
        $groups = [];

        foreach ($paths as $path) {
            foreach (File::isDirectory($path) ? File::directories($path) : [] as $directory) {
                if (basename($directory) === 'vendor') {
                    continue;
                }

                foreach (File::glob($directory.'/*.php') as $file) {
                    $groups[] = basename($file, '.php');
                }
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * @param  array<string, mixed>  $array
     */
    protected function exportArray(array $array, int $depth = 1): string
    {
        $indent = str_repeat('    ', $depth);
        $lines = [];

        foreach ($array as $key => $value) {
            $lines[] = $indent.var_export((string) $key, true).' => '.(is_array($value) ? $this->exportArray($value, $depth + 1) : var_export($value, true)).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth - 1).']';
    }
}
