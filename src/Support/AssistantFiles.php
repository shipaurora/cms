<?php

namespace Aurora\Support;

use Aurora\Enums\AssetType;
use Aurora\Models\Asset;
use Aurora\Models\User;
use EslamRedaDiv\FilamentCopilot\Enums\MessageRole;
use EslamRedaDiv\FilamentCopilot\Models\CopilotMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Files\Image;
use Spatie\Image\Image as SpatieImage;
use Throwable;

/**
 * Files a person attaches in the assistant's chat.
 *
 * They are kept beside the media library, not in it. The assistant can read them
 * straight away (an image as a picture, a PDF or text or CSV as text). A file is
 * copied into the library only when a page tool puts its id in a file or image field.
 */
class AssistantFiles
{
    public const MAX = 4;

    /**
     * How much of a document is pasted into the prompt. The rest is left out.
     */
    public const TEXT_LIMIT = 12_000;

    /**
     * @return array{id: string, title: string, url: ?string, kind: string, icon: string}
     */
    public static function store(UploadedFile $file, User $user): array
    {
        static::prune($user);

        $mime = (string) $file->getMimeType();
        $kind = static::kind($mime, $file->getClientOriginalName());

        if ($kind === null) {
            throw new InvalidArgumentException((string) __('Attach an image, a PDF, or a text or CSV file.'));
        }

        $id = 'tmp_'.Str::lower((string) Str::ulid());
        $extension = static::extension($mime, $kind);
        $title = Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->replace(['-', '_'], ' ')->squish()->ucfirst()->toString();
        $directory = static::directory($user);

        Storage::disk('local')->put($directory.'/'.$id.$extension, $file->getContent());
        Storage::disk('local')->put($directory.'/'.$id.'.json', json_encode([
            'title' => $title,
            'mime' => $mime,
            'extension' => $extension,
            'kind' => $kind,
        ], JSON_THROW_ON_ERROR));

        if ($kind === 'image') {
            rescue(fn () => SpatieImage::load(Storage::disk('local')->path($directory.'/'.$id.$extension))
                ->width(480)
                ->save(Storage::disk('local')->path($directory.'/'.$id.'-thumb.jpg')), report: false);
        }

        return [
            'id' => $id,
            'title' => $title,
            'url' => $kind === 'image' ? route('assistant-files.show', ['file' => $id]) : null,
            'kind' => $kind,
            'icon' => static::icon($kind),
        ];
    }

    public static function rejection(UploadedFile $file): ?string
    {
        $mime = (string) $file->getMimeType();

        if (static::kind($mime, $file->getClientOriginalName()) === null) {
            return __('Attach an image, a PDF, or a text or CSV file.');
        }

        $type = AssetType::fromMimeType($mime);
        $max = $type->maxKilobytes() * 1024;

        if ($file->getSize() > $max) {
            return __('Larger than :size.', ['size' => Number::fileSize($max)]);
        }

        return null;
    }

    public static function delete(string $id, ?Model $user): void
    {
        if (! $user instanceof User || ! static::isTemporary($id)) {
            return;
        }

        $directory = static::directory($user);
        $meta = static::meta($user, $id);

        Storage::disk('local')->delete($directory.'/'.$id.'.json');
        Storage::disk('local')->delete($directory.'/'.$id.'-thumb.jpg');

        if ($meta !== null) {
            Storage::disk('local')->delete($directory.'/'.$id.$meta['extension']);
        }
    }

    /**
     * The prompt the model should see, and the pictures to send with it.
     *
     * Documents are written into the prompt. The local model only accepts pictures
     * as attachments, so a PDF or a spreadsheet is not sent as a file.
     *
     * @return array{0: string, 1: array<int, Image>}
     */
    public static function attach(mixed $ids, string $prompt, ?Model $user): array
    {
        $files = static::files($ids, $user);

        if ($files === [] || ! $user instanceof User) {
            return [$prompt, []];
        }

        static::remember($prompt, $user, $files);

        $attachments = [];

        foreach ($files as $file) {
            if ($file['kind'] !== 'image') {
                continue;
            }

            $directory = static::directory($user);
            $thumb = $directory.'/'.$file['id'].'-thumb.jpg';
            $relative = Storage::disk('local')->exists($thumb) ? $thumb : $directory.'/'.$file['id'].$file['extension'];
            $contents = Storage::disk('local')->get($relative);

            if (is_string($contents) && $contents !== '') {
                $attachments[] = Image::fromBase64(base64_encode($contents), $relative === $thumb ? 'image/jpeg' : $file['mime']);
            }
        }

        return [$prompt."\n\n".static::note($files), $attachments];
    }

    /**
     * Copy attached files that a page tool placed in a field into the media library,
     * and swap their temporary ids for the library ids.
     *
     * @param  array<int, Asset>  $created
     */
    public static function claimWithin(mixed $value, array &$created): mixed
    {
        if (is_string($value) && static::isTemporary($value)) {
            $asset = static::claim($value);
            $created[] = $asset;

            return $asset->id;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (is_string($value['asset'] ?? null) && static::isTemporary($value['asset'])) {
            $asset = static::claim($value['asset']);
            $created[] = $asset;
            $value['asset'] = $asset->id;

            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = static::claimWithin($item, $created);
        }

        return $value;
    }

    /**
     * @param  array<int, Asset>  $created
     */
    public static function discard(array $created): void
    {
        foreach ($created as $asset) {
            foreach (static::$claimed as $id => $claimed) {
                if ($claimed->is($asset)) {
                    unset(static::$claimed[$id]);
                }
            }

            $asset->delete();
        }
    }

    /**
     * The absolute path of an image preview, for the signed-in person who attached it.
     */
    public static function previewPath(string $id, ?Model $user): ?string
    {
        if (! $user instanceof User || ! static::isTemporary($id)) {
            return null;
        }

        $meta = static::meta($user, $id);

        if ($meta === null || $meta['kind'] !== 'image') {
            return null;
        }

        $directory = static::directory($user);
        $thumb = $directory.'/'.$id.'-thumb.jpg';
        $original = $directory.'/'.$id.$meta['extension'];
        $relative = Storage::disk('local')->exists($thumb) ? $thumb : $original;

        return Storage::disk('local')->exists($relative) ? Storage::disk('local')->path($relative) : null;
    }

    public static function isTemporary(string $id): bool
    {
        return preg_match('/^tmp_[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1;
    }

    /**
     * @param  array<int, array{id: string, title: string, url: ?string, kind: string, icon: string, mime: string, extension: string, text: ?string}>  $files
     */
    protected static function note(array $files): string
    {
        $lines = [
            'The user attached these files. They are not in the media library. To put one on a block, pass its id in the file or image field: it is saved into the library at that moment. Do not call upload_image_from_url for them, and do not ask the user to upload them again.',
        ];

        foreach ($files as $file) {
            $lines[] = "- id: {$file['id']}\n  name: {$file['title']}\n  kind: {$file['kind']}";

            if ($file['kind'] === 'image') {
                $lines[] = '  alt: (none yet; write alt text from what you see)';
            }

            if (filled($file['text'])) {
                $lines[] = "  text:\n".$file['text'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{id: string, title: string, url: ?string, kind: string, icon: string, mime: string, extension: string, text: ?string}>
     */
    protected static function files(mixed $ids, ?Model $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return collect(Arr::wrap($ids))
            ->filter(fn (mixed $id): bool => is_string($id) && static::isTemporary($id))
            ->unique()
            ->take(self::MAX)
            ->map(function (string $id) use ($user): ?array {
                $meta = static::meta($user, $id);

                if ($meta === null) {
                    return null;
                }

                $text = $meta['kind'] === 'image' ? null : static::excerpt($user, $id, $meta);

                return [
                    'id' => $id,
                    'title' => $meta['title'],
                    'url' => $meta['kind'] === 'image' ? route('assistant-files.show', ['file' => $id]) : null,
                    'kind' => $meta['kind'],
                    'icon' => static::icon($meta['kind']),
                    'mime' => $meta['mime'],
                    'extension' => $meta['extension'],
                    'text' => $text,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{title: string, mime: string, extension: string, kind: string}  $meta
     */
    protected static function excerpt(User $user, string $id, array $meta): ?string
    {
        $raw = Storage::disk('local')->get(static::directory($user).'/'.$id.$meta['extension']);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $text = $meta['mime'] === 'application/pdf' ? static::pdfText($raw) : $raw;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return $meta['mime'] === 'application/pdf'
                ? '(No text could be read from this PDF. It may be a scan.)'
                : null;
        }

        if (mb_strlen($text) > self::TEXT_LIMIT) {
            return mb_substr($text, 0, self::TEXT_LIMIT).'… (truncated)';
        }

        return $text;
    }

    /**
     * Pull the readable strings out of a PDF, including streams compressed the usual way.
     */
    protected static function pdfText(string $binary): string
    {
        $chunks = [$binary];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $binary, $matches)) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream) ?: @gzinflate($stream);

                if (is_string($inflated) && $inflated !== '') {
                    $chunks[] = $inflated;
                }
            }
        }

        $text = '';

        foreach ($chunks as $chunk) {
            if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/', $chunk, $parts)) {
                foreach ($parts[1] as $part) {
                    $text .= stripcslashes($part).' ';
                }
            }

            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $chunk, $arrays)) {
                foreach ($arrays[1] as $array) {
                    if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $array, $bits)) {
                        foreach ($bits[1] as $bit) {
                            $text .= stripcslashes($bit);
                        }

                        $text .= ' ';
                    }
                }
            }
        }

        return $text;
    }

    /**
     * @param  array<int, array{id: string, title: string, url: ?string, kind: string, icon: string}>  $files
     */
    protected static function remember(string $prompt, ?Model $user, array $files): void
    {
        if ($user === null) {
            return;
        }

        $message = CopilotMessage::query()
            ->where('role', MessageRole::User)
            ->where('content', $prompt)
            ->whereHas('conversation', fn ($query) => $query->forParticipant($user))
            ->latest('created_at')
            ->first();

        $message?->update([
            'metadata' => [
                'files' => array_map(fn (array $file): array => [
                    'id' => $file['id'],
                    'title' => $file['title'],
                    'url' => $file['url'],
                    'kind' => $file['kind'],
                    'icon' => $file['icon'],
                ], $files),
            ],
        ]);
    }

    /** @var array<string, Asset> */
    protected static array $claimed = [];

    protected static function claim(string $id): Asset
    {
        if (isset(static::$claimed[$id])) {
            return static::$claimed[$id];
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('That attached file could not be saved.');
        }

        $meta = static::meta($user, $id);
        $relative = $meta === null ? null : static::directory($user).'/'.$id.$meta['extension'];

        if ($relative === null || ! Storage::disk('local')->exists($relative)) {
            throw new InvalidArgumentException('That attached file is no longer available. Ask them to attach it again.');
        }

        try {
            $asset = AssetLibrary::fromFile(Storage::disk('local')->path($relative), ['title' => $meta['title']]);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('That attached file could not be saved into the media library.');
        }

        return static::$claimed[$id] = $asset;
    }

    /**
     * @return array{title: string, mime: string, extension: string, kind: string}|null
     */
    protected static function meta(User $user, string $id): ?array
    {
        $json = Storage::disk('local')->get(static::directory($user).'/'.$id.'.json');

        if (! is_string($json)) {
            return null;
        }

        $meta = json_decode($json, true);

        if (! is_array($meta)) {
            return null;
        }

        $extension = $meta['extension'] ?? null;
        $kind = $meta['kind'] ?? null;

        if (! is_string($extension) || ! is_string($meta['mime'] ?? null) || ! is_string($kind)) {
            return null;
        }

        if (! in_array($extension, ['.jpg', '.png', '.webp', '.gif', '.avif', '.pdf', '.csv', '.txt'], true)) {
            return null;
        }

        if (! in_array($kind, ['image', 'pdf', 'csv', 'txt'], true)) {
            return null;
        }

        return [
            'title' => is_string($meta['title'] ?? null) ? $meta['title'] : 'File',
            'mime' => $meta['mime'],
            'extension' => $extension,
            'kind' => $kind,
        ];
    }

    protected static function kind(string $mime, string $name): ?string
    {
        if (in_array($mime, AssetType::Image->mimeTypes(), true)) {
            return 'image';
        }

        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($mime, ['text/csv', 'application/csv'], true) || ($mime === 'text/plain' && $extension === 'csv')) {
            return 'csv';
        }

        if ($mime === 'text/plain' && in_array($extension, ['txt', 'text', 'md'], true)) {
            return 'txt';
        }

        return null;
    }

    protected static function extension(string $mime, string $kind): string
    {
        return match ($kind) {
            'pdf' => '.pdf',
            'csv' => '.csv',
            'txt' => '.txt',
            default => match ($mime) {
                'image/png' => '.png',
                'image/webp' => '.webp',
                'image/gif' => '.gif',
                'image/avif' => '.avif',
                default => '.jpg',
            },
        };
    }

    protected static function icon(string $kind): string
    {
        return match ($kind) {
            'image' => 'phosphor-file-image',
            'pdf' => 'phosphor-file-pdf',
            'csv' => 'phosphor-file-xls',
            default => 'phosphor-file-txt',
        };
    }

    protected static function directory(User $user): string
    {
        return 'assistant-files/'.$user->getKey();
    }

    protected static function prune(User $user): void
    {
        $directory = static::directory($user);
        $cutoff = now()->subDays(7)->getTimestamp();

        foreach (Storage::disk('local')->files($directory) as $path) {
            if (Storage::disk('local')->lastModified($path) < $cutoff) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
