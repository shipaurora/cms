<?php

namespace Aurora\Enums;

use Aurora\Models\Asset;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * What kind of file a media library asset is, worked out from its MIME type. Decides the
 * icon a file is shown with, what the type filter offers, and where a file may go: image
 * fields only take images.
 *
 * Only the MIME types listed here can be uploaded. Everything else (HTML, scripts,
 * executables) is refused: files are served from the site's own domain.
 */
enum AssetType: string implements HasIcon, HasLabel
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Pdf = 'pdf';
    case Document = 'document';
    case Spreadsheet = 'spreadsheet';
    case Presentation = 'presentation';
    case Archive = 'archive';
    case Text = 'text';
    case Other = 'other';

    /**
     * Largest image upload, in kilobytes (aurora.media.max_image_mb): images stay small
     * enough to resize quickly.
     */
    public static function maxImageKilobytes(): int
    {
        return (int) round((float) config('aurora.media.max_image_mb', 10) * 1024);
    }

    /**
     * Largest upload of any other file, in kilobytes (aurora.media.max_file_mb).
     */
    public static function maxFileKilobytes(): int
    {
        return (int) round((float) config('aurora.media.max_file_mb', 50) * 1024);
    }

    /**
     * Largest upload of any type, in kilobytes: what Livewire and the media library let through.
     */
    public static function largestUploadKilobytes(): int
    {
        return max(self::maxImageKilobytes(), self::maxFileKilobytes());
    }

    /**
     * @return array<int, string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            // No SVG: it can carry script, and the library serves files from the admin's own origin.
            self::Image => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
            self::Video => ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg'],
            self::Audio => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/flac', 'audio/webm'],
            self::Pdf => ['application/pdf'],
            self::Document => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.oasis.opendocument.text',
                'application/rtf',
                'text/rtf',
            ],
            self::Spreadsheet => [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.spreadsheet',
                'text/csv',
            ],
            self::Presentation => [
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.presentation',
            ],
            self::Archive => ['application/zip', 'application/x-zip-compressed'],
            self::Text => ['text/plain', 'text/markdown'],
            self::Other => [],
        };
    }

    public static function fromMimeType(?string $mimeType): self
    {
        foreach (self::cases() as $type) {
            if (in_array($mimeType, $type->mimeTypes(), true)) {
                return $type;
            }
        }

        return self::Other;
    }

    /**
     * Every MIME type that may be uploaded.
     *
     * @return array<int, string>
     */
    public static function allowedMimeTypes(): array
    {
        return array_merge(...array_map(fn (self $type): array => $type->mimeTypes(), self::cases()));
    }

    /**
     * Limit a query to assets of this type.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scope(Builder $query): Builder
    {
        return $this === self::Other
            ? $query->where(fn (Builder $query) => $query->whereNull('mime_type')->orWhereNotIn('mime_type', self::allowedMimeTypes()))
            : $query->whereIn('mime_type', $this->mimeTypes());
    }

    public function maxKilobytes(): int
    {
        return $this === self::Image ? self::maxImageKilobytes() : self::maxFileKilobytes();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Image => __('Image'),
            self::Video => __('Video'),
            self::Audio => __('Audio'),
            self::Pdf => __('PDF'),
            self::Document => __('Document'),
            self::Spreadsheet => __('Spreadsheet'),
            self::Presentation => __('Presentation'),
            self::Archive => __('Zip archive'),
            self::Text => __('Text'),
            self::Other => __('Other file'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Image => 'phosphor-file-image',
            self::Video => 'phosphor-file-video',
            self::Audio => 'phosphor-file-audio',
            self::Pdf => 'phosphor-file-pdf',
            self::Document => 'phosphor-file-doc',
            self::Spreadsheet => 'phosphor-file-xls',
            self::Presentation => 'phosphor-file-ppt',
            self::Archive => 'phosphor-file-zip',
            self::Text => 'phosphor-file-txt',
            self::Other => 'phosphor-file',
        };
    }
}
