<?php

namespace Aurora\Support;

use Aurora\Models\Asset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The EXIF data a camera or editor wrote into an image (camera, exposure, when and where it
 * was taken), read from the original file. Only JPEG and TIFF carry EXIF that PHP can read.
 */
class ImageMetadata
{
    /**
     * @var array<int, string>
     */
    public const READABLE_MIME_TYPES = ['image/jpeg', 'image/tiff'];

    /**
     * What was read per file: the section's visibility and its content ask for it in one request.
     *
     * @var array<string, array{summary: array<string, array{value: string, url?: string}>, all: array<string, string>}>
     */
    protected static array $read = [];

    /**
     * Whether the file is a kind that carries this data and actually has some.
     */
    public static function has(?Asset $asset): bool
    {
        return static::supports($asset) && static::read($asset)['all'] !== [];
    }

    /**
     * The file as it is now: a replaced file is read again.
     */
    protected static function key(Asset $asset): string
    {
        $file = $asset->file();

        return $asset->getKey().':'.$file?->getKey().':'.$file?->updated_at?->getTimestamp();
    }

    public static function supports(?Asset $asset): bool
    {
        return $asset !== null && function_exists('exif_read_data') && in_array($asset->mime_type, self::READABLE_MIME_TYPES, true);
    }

    /**
     * Who to credit for a photo, from its EXIF: the Artist, else the Copyright, or null when it
     * has neither. Read from a file on this server, as it's added to the library.
     */
    public static function credit(?string $path): ?string
    {
        if ($path === null || ! function_exists('exif_read_data') || ! is_file($path) || ! in_array(mime_content_type($path), self::READABLE_MIME_TYPES, true)) {
            return null;
        }

        try {
            $tags = (@exif_read_data($path, 'IFD0', true) ?: [])['IFD0'] ?? [];
        } catch (Throwable) {
            return null;
        }

        foreach (['Artist', 'Copyright'] as $name) {
            // A copyright can hold the photographer's and the editor's, apart by a NUL byte.
            $value = is_string($tags[$name] ?? null) ? Str::before($tags[$name], "\0") : '';
            $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

            if ($value !== '') {
                return Str::limit($value, 255, '');
            }
        }

        return null;
    }

    /**
     * The useful parts, in plain words, by label ("Camera" => "Fujifilm X-T5"), and every tag
     * as stored.
     *
     * @return array{summary: array<string, array{value: string, url?: string}>, all: array<string, string>}
     */
    public static function read(Asset $asset): array
    {
        return static::$read[static::key($asset)] ??= (function () use ($asset): array {
            $exif = static::raw($asset);

            return ['summary' => static::summarise($exif), 'all' => static::flatten($exif)];
        })();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function raw(Asset $asset): array
    {
        $file = $asset->file();

        if (! static::supports($asset) || $file === null) {
            return [];
        }

        try {
            $stream = Storage::disk($file->disk)->readStream($file->getPathRelativeToRoot());

            if (! is_resource($stream)) {
                return [];
            }

            $exif = @exif_read_data($stream, null, true) ?: [];
            fclose($stream);

            // Not EXIF: the file as PHP saw it, the embedded thumbnail and the JPEG comment.
            return array_diff_key($exif, array_flip(['FILE', 'COMPUTED', 'THUMBNAIL', 'COMMENT']));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $exif
     * @return array<string, array{value: string, url?: string}>
     */
    protected static function summarise(array $exif): array
    {
        $tag = fn (string $name): mixed => collect($exif)->first(fn (mixed $section): bool => is_array($section) && array_key_exists($name, $section))[$name] ?? null;
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $make = $text($tag('Make'));
        $model = $text($tag('Model'));
        // Many cameras repeat the maker in the model name ("Canon" / "Canon EOS R5").
        $camera = $model !== null && $make !== null && ! str_starts_with(mb_strtolower($model), mb_strtolower($make)) ? "{$make} {$model}" : ($model ?? $make);

        $exposure = static::number($tag('ExposureTime'));
        $aperture = static::number($tag('FNumber'));
        $focal = static::number($tag('FocalLength'));
        $iso = $tag('ISOSpeedRatings');
        $flash = $tag('Flash');

        $summary = [
            __('Camera') => $camera,
            __('Lens') => $text($tag('UndefinedTag:0xA434')) ?? $text($tag('LensModel')),
            __('Taken') => static::date($text($tag('DateTimeOriginal')) ?? $text($tag('DateTime'))),
            __('Exposure') => $exposure === null ? null : ($exposure < 1 && $exposure > 0 ? '1/'.round(1 / $exposure).' s' : round($exposure, 1).' s'),
            __('Aperture') => $aperture === null ? null : 'ƒ/'.round($aperture, 1),
            __('ISO') => is_array($iso) ? (string) reset($iso) : (is_numeric($iso) ? (string) $iso : null),
            __('Focal length') => $focal === null ? null : round($focal).' mm',
            __('Flash') => is_numeric($flash) ? (((int) $flash & 1) === 1 ? __('Fired') : __('Didn’t fire')) : null,
            __('Artist') => $text($tag('Artist')),
            __('Copyright') => $text($tag('Copyright')),
            __('Software') => $text($tag('Software')),
        ];

        $summary = array_map(fn (string $value): array => ['value' => $value], array_filter($summary, fn (?string $value): bool => $value !== null));

        if (($location = static::location($exif['GPS'] ?? [])) !== null) {
            $summary[__('Location')] = [
                'value' => sprintf('%.5f, %.5f', ...$location),
                'url' => sprintf('https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=15/%1$.6f/%2$.6f', ...$location),
            ];
        }

        return $summary;
    }

    /**
     * Every tag with a value that reads as text, by "Section › Tag".
     *
     * @param  array<string, mixed>  $exif
     * @return array<string, string>
     */
    protected static function flatten(array $exif): array
    {
        $all = [];

        foreach ($exif as $section => $tags) {
            foreach (is_array($tags) ? $tags : [] as $name => $value) {
                $value = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : $value;

                // Binary blobs (maker notes and the like) aren't worth showing.
                if (is_scalar($value) && (string) $value !== '' && mb_check_encoding((string) $value, 'UTF-8') && ! preg_match('/[\x00-\x08\x0E-\x1F]/', (string) $value)) {
                    $all["{$section} › {$name}"] = mb_strimwidth((string) $value, 0, 200, '…');
                }
            }
        }

        return $all;
    }

    /**
     * "1/250" or "28/10" as a number.
     */
    protected static function number(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('#^(-?\d+)/(\d+)$#', $value, $parts) && (int) $parts[2] !== 0) {
            return (int) $parts[1] / (int) $parts[2];
        }

        return null;
    }

    protected static function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y:m:d H:i:s', $value)?->isoFormat('lll');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Latitude and longitude in degrees, from the GPS section.
     *
     * @param  array<string, mixed>  $gps
     * @return array{0: float, 1: float}|null
     */
    protected static function location(array $gps): ?array
    {
        $degrees = function (mixed $parts, mixed $ref): ?float {
            if (! is_array($parts) || count($parts) < 3) {
                return null;
            }

            [$d, $m, $s] = array_map(fn (mixed $part): ?float => static::number($part), array_values($parts));

            if ($d === null || $m === null || $s === null) {
                return null;
            }

            $value = $d + $m / 60 + $s / 3600;

            return in_array($ref, ['S', 'W'], true) ? -$value : $value;
        };

        $latitude = $degrees($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
        $longitude = $degrees($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);

        return $latitude === null || $longitude === null ? null : [$latitude, $longitude];
    }
}
