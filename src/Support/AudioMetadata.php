<?php

namespace Aurora\Support;

use Aurora\Models\Asset;
use getID3;
use getid3_lib;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * The tags in an audio file (title, artist, album…) and how it was encoded, read from the
 * original with getID3: ID3 in MP3s, Vorbis comments in FLAC and Ogg, and the tags in M4A
 * and WAV files.
 */
class AudioMetadata
{
    /**
     * @var array<int, string>
     */
    public const READABLE_MIME_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/flac'];

    /**
     * The tags in plain words, by getID3's merged name for them.
     *
     * @var array<string, string>
     */
    protected const LABELS = [
        'title' => 'Title',
        'artist' => 'Artist',
        'album' => 'Album',
        'band' => 'Album artist',
        'year' => 'Year',
        'track_number' => 'Track',
        'part_of_a_set' => 'Disc',
        'genre' => 'Genre',
        'composer' => 'Composer',
        'bpm' => 'BPM',
        'publisher' => 'Publisher',
        'copyright' => 'Copyright',
        'comment' => 'Comment',
    ];

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
        return $asset !== null && in_array($asset->mime_type, self::READABLE_MIME_TYPES, true);
    }

    /**
     * MP3s carry ID3: what the section is called for them.
     */
    public static function isId3(?Asset $asset): bool
    {
        return in_array($asset?->mime_type, ['audio/mpeg', 'audio/mp3'], true);
    }

    /**
     * The useful parts by label ("Artist" => "Nils Frahm", "Duration" => "4:12"), and every
     * tag as stored.
     *
     * @return array{summary: array<string, array{value: string}>, all: array<string, string>}
     */
    public static function read(Asset $asset): array
    {
        return static::$read[static::key($asset)] ??= static::readFresh($asset);
    }

    /**
     * @return array{summary: array<string, array{value: string}>, all: array<string, string>}
     */
    protected static function readFresh(Asset $asset): array
    {
        $info = static::analyze($asset);
        // Text values only: cover art (and the like) comes as nested arrays of binary data.
        $tags = array_map(fn (mixed $values): string => implode(' / ', array_filter(
            array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', (array) $values),
            fn (string $value): bool => trim($value) !== '',
        )), collect($info['comments'] ?? [])->except('picture')->all());
        $tags = array_filter($tags, fn (string $value): bool => $value !== '' && mb_check_encoding($value, 'UTF-8'));

        $summary = [];

        foreach (self::LABELS as $tag => $label) {
            if (isset($tags[$tag])) {
                $summary[__($label)] = ['value' => $tags[$tag]];
            }
        }

        $audio = $info['audio'] ?? [];
        $summary += array_map(fn (string $value): array => ['value' => $value], array_filter([
            __('Duration') => $info['playtime_string'] ?? null,
            __('Bitrate') => isset($info['bitrate']) ? round($info['bitrate'] / 1000).' kbps' : null,
            __('Sample rate') => isset($audio['sample_rate']) ? Number::format($audio['sample_rate'] / 1000, maxPrecision: 1).' kHz' : null,
            __('Channels') => match ($audio['channels'] ?? null) {
                1 => __('Mono'),
                2 => __('Stereo'),
                null => null,
                default => (string) $audio['channels'],
            },
        ]));

        return ['summary' => $summary, 'all' => collect($tags)->sortKeys()->all()];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function analyze(Asset $asset): array
    {
        $file = $asset->file();

        if (! static::supports($asset) || $file === null) {
            return [];
        }

        $disk = Storage::disk($file->disk);
        $path = $file->getPathRelativeToRoot();
        // getID3 reads from a file on this server: a copy when the file lives elsewhere.
        $local = rescue(fn (): ?string => $disk->path($path), null, report: false);
        $copy = null;

        if ($local === null || ! is_file($local)) {
            $copy = tempnam(sys_get_temp_dir(), 'aurora-audio-');
            file_put_contents($copy, $disk->readStream($path));
            $local = $copy;
        }

        try {
            $info = (new getID3)->analyze($local);
            getid3_lib::CopyTagsToComments($info);

            return $info;
        } catch (Throwable) {
            return [];
        } finally {
            if ($copy !== null) {
                @unlink($copy);
            }
        }
    }
}
