<?php

namespace Aurora\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The website's own stylesheet on the visual editor's canvas, so blocks look there exactly as
 * they do on the website, whatever the site builds its CSS with. The canvas sits inside the
 * admin page, so its rules are wrapped in @scope first: they apply only inside the canvas,
 * just as they would on the website, and what they say of the page itself (:root, html,
 * body) they say of the canvas. Found through Vite (config('aurora.website_css')): the built
 * file, or the dev server while it runs.
 */
class CanvasStyles
{
    /**
     * The element the website's styles are scoped to.
     */
    public const ROOT = '.fpb-canvas';

    /**
     * At-rules whose blocks hold rules to scope; any other block (@keyframes, @font-face,
     *
     * @property …) is kept as it is.
     *
     * @var array<int, string>
     */
    protected const GROUPING = ['media', 'supports', 'layer', 'container', 'scope', 'starting-style'];

    /**
     * The address the editor loads them from, with what changes when the stylesheet does (for
     * caching), or nothing when the site has no stylesheet to show.
     */
    public static function url(): ?string
    {
        $version = static::version();

        return $version === null ? null : route('aurora.canvas-styles', ['v' => $version]);
    }

    /**
     * The website's fonts from Laravel's Vite font loading (fonts in vite.config.js, @vite's
     * Vite::fonts() in the layout's head), which its stylesheet doesn't carry: the canvas
     * needs them too. Nothing for a site that loads fonts some other way.
     */
    public static function fonts(): HtmlString
    {
        $vite = app(Vite::class);

        return method_exists($vite, 'fonts') ? rescue(fn (): HtmlString => $vite->fonts(), new HtmlString(''), report: false) : new HtmlString('');
    }

    /**
     * Whether they come from the Vite dev server (and so change without a new address).
     */
    public static function isHot(): bool
    {
        return is_file(public_path('hot'));
    }

    /**
     * The website's stylesheet, scoped to the canvas.
     */
    public static function css(): ?string
    {
        $source = static::source();

        if ($source === null) {
            return null;
        }

        return Cache::rememberForever('aurora.canvas-styles.'.sha1($source), fn (): string => static::scope($source));
    }

    /**
     * Every rule of a stylesheet, made to apply only inside the canvas.
     */
    public static function scope(string $css, string $root = self::ROOT): string
    {
        return static::scopeBlock(static::withoutComments($css), $root);
    }

    protected static function version(): ?string
    {
        if (static::isHot()) {
            return 'dev';
        }

        return static::manifestEntry()['file'] ?? null;
    }

    protected static function source(): ?string
    {
        $entry = (string) config('aurora.website_css');

        if ($entry === '') {
            return null;
        }

        if (static::isHot()) {
            $server = rtrim(trim(File::get(public_path('hot'))), '/');
            $response = rescue(fn (): Response => Http::timeout(3)->get($server.'/'.$entry.'?direct'), null, report: false);

            // The dev server's files (fonts, images) are on the dev server, not on this site.
            return $response?->successful() ? preg_replace('#url\(\s*([\'"]?)/(?!/)#', 'url($1'.$server.'/', $response->body()) : null;
        }

        $file = static::manifestEntry()['file'] ?? null;

        return $file !== null && is_file($path = public_path('build/'.$file)) ? File::get($path) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function manifestEntry(): ?array
    {
        try {
            $manifest = json_decode(File::get(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return $manifest[(string) config('aurora.website_css')] ?? null;
    }

    protected static function scopeBlock(string $css, string $root): string
    {
        $out = '';
        $rules = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            [$prelude, $end, $i] = static::readPrelude($css, $i);
            $prelude = trim($prelude);

            if ($prelude === '' && $end !== '{') {
                continue;
            }

            if ($end !== '{') {
                $out .= static::scoped($rules, $root).$prelude.';';
                $rules = '';

                continue;
            }

            [$body, $i] = static::readBlock($css, $i);

            if (! str_starts_with($prelude, '@')) {
                $rules .= implode(',', array_unique(array_map(static::scopeSelector(...), static::splitSelectors($prelude)))).'{'.$body.'}';

                continue;
            }

            $name = strtolower((string) preg_replace('/^@(?:-[a-z]+-)?([a-z-]+).*$/s', '$1', $prelude));
            $out .= static::scoped($rules, $root).$prelude.'{'.(in_array($name, self::GROUPING, true) ? static::scopeBlock($body, $root) : $body).'}';
            $rules = '';
        }

        return $out.static::scoped($rules, $root);
    }

    /**
     * A run of style rules, applying only inside the canvas.
     */
    protected static function scoped(string $rules, string $root): string
    {
        return $rules === '' ? '' : '@scope ('.$root.'){'.$rules.'}';
    }

    /**
     * One selector, for inside the canvas: the page itself (:root, :host, html, body) is the
     * canvas, keeping what qualifies it (html.dark is the canvas with that class, which it
     * never has: the canvas shows the site as visitors first see it).
     */
    protected static function scopeSelector(string $selector): string
    {
        $selector = trim($selector);
        $page = false;
        $qualifiers = '';

        while (preg_match('/^(?::root|:host|html|body)(?![\w-])([^\s>+~]*)\s*>?\s*/i', $selector, $match)) {
            $page = true;
            $qualifiers .= $match[1];
            $selector = substr($selector, strlen($match[0]));
        }

        if (! $page) {
            return $selector;
        }

        return ':scope'.$qualifiers.($selector === '' ? '' : ' '.$selector);
    }

    /**
     * @return array{0: string, 1: ?string, 2: int}
     */
    protected static function readPrelude(string $css, int $i): array
    {
        $start = $i;
        $length = strlen($css);
        $depth = 0;

        for (; $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '"' || $char === "'") {
                $i = static::skipString($css, $i);

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($depth === 0 && ($char === '{' || $char === ';')) {
                return [substr($css, $start, $i - $start), $char, $i + 1];
            }
        }

        return [substr($css, $start), null, $length];
    }

    /**
     * A block's contents, from just after its { to its matching }.
     *
     * @return array{0: string, 1: int}
     */
    protected static function readBlock(string $css, int $i): array
    {
        $start = $i;
        $length = strlen($css);
        $depth = 1;

        for (; $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '"' || $char === "'") {
                $i = static::skipString($css, $i);

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return [substr($css, $start, $i - $start), $i + 1];
            }
        }

        return [substr($css, $start), $length];
    }

    protected static function skipString(string $css, int $i): int
    {
        $quote = $css[$i];
        $length = strlen($css);

        for ($i++; $i < $length; $i++) {
            if ($css[$i] === '\\') {
                $i++;
            } elseif ($css[$i] === $quote) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * @return array<int, string>
     */
    protected static function splitSelectors(string $prelude): array
    {
        $selectors = [];
        $depth = 0;
        $current = '';
        $length = strlen($prelude);

        for ($i = 0; $i < $length; $i++) {
            $char = $prelude[$i];

            if ($char === '"' || $char === "'") {
                $end = static::skipString($prelude, $i);
                $current .= substr($prelude, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $selectors[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $selectors[] = $current;

        return $selectors;
    }

    protected static function withoutComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
