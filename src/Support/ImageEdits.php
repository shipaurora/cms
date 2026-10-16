<?php

namespace Aurora\Support;

use Illuminate\Support\Str;

/**
 * What one block did to a library image: crop, rotation, flips and tone.
 *
 * An image field holds either a bare asset id (unedited) or
 *
 *     ['asset' => '<uuid>', 'crop' => ['x' => .1, 'y' => 0, 'width' => .8, 'height' => .6],
 *      'aspect' => '16/9', 'rotate' => 90, 'flip_x' => false, 'flip_y' => false,
 *      'exposure' => 10, 'contrast' => -5, 'grayscale' => false]
 *
 * so the same image can be framed differently in every block that uses it. The crop is
 * stored as fractions of the (rotated) original, which keeps it valid if the original
 * file is replaced with a larger or smaller version. The original is never changed.
 */
final class ImageEdits
{
    /**
     * @param  array{x: float, y: float, width: float, height: float}|null  $crop
     */
    public function __construct(
        public readonly ?array $crop = null,
        public readonly ?string $aspect = null,
        public readonly int $rotate = 0,
        public readonly bool $flipX = false,
        public readonly bool $flipY = false,
        public readonly int $exposure = 0,
        public readonly int $contrast = 0,
        public readonly bool $grayscale = false,
    ) {}

    /**
     * The asset id held by an image field value, whatever its shape.
     */
    public static function assetId(mixed $value): ?string
    {
        $id = is_array($value) ? ($value['asset'] ?? null) : $value;

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    public static function from(mixed $value): self
    {
        if (! is_array($value)) {
            return new self;
        }

        $crop = is_array($value['crop'] ?? null) ? $value['crop'] : null;

        if ($crop !== null) {
            $crop = [
                'x' => self::fraction($crop['x'] ?? 0),
                'y' => self::fraction($crop['y'] ?? 0),
                'width' => self::fraction($crop['width'] ?? 1),
                'height' => self::fraction($crop['height'] ?? 1),
            ];

            // A crop covering the whole image is no crop.
            if ($crop['width'] >= 0.999 && $crop['height'] >= 0.999) {
                $crop = null;
            }
        }

        $rotate = (int) ($value['rotate'] ?? 0);

        return new self(
            crop: $crop,
            aspect: is_string($value['aspect'] ?? null) && preg_match('/^\d+\/\d+$/', $value['aspect']) ? $value['aspect'] : null,
            rotate: in_array($rotate, [0, 90, 180, 270], true) ? $rotate : 0,
            flipX: (bool) ($value['flip_x'] ?? false),
            flipY: (bool) ($value['flip_y'] ?? false),
            exposure: max(-100, min(100, (int) ($value['exposure'] ?? 0))),
            contrast: max(-100, min(100, (int) ($value['contrast'] ?? 0))),
            grayscale: (bool) ($value['grayscale'] ?? false),
        );
    }

    public function isEmpty(): bool
    {
        return $this->crop === null && $this->rotate === 0 && ! $this->flipX && ! $this->flipY
            && $this->exposure === 0 && $this->contrast === 0 && ! $this->grayscale;
    }

    /**
     * The value to store in a block field: a bare id when nothing was changed.
     *
     * @return string|array<string, mixed>
     */
    public function toValue(string $assetId): string|array
    {
        if ($this->isEmpty()) {
            return $assetId;
        }

        return array_filter([
            'asset' => $assetId,
            'crop' => $this->crop,
            'aspect' => $this->aspect,
            'rotate' => $this->rotate ?: null,
            'flip_x' => $this->flipX ?: null,
            'flip_y' => $this->flipY ?: null,
            'exposure' => $this->exposure ?: null,
            'contrast' => $this->contrast ?: null,
            'grayscale' => $this->grayscale ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * A short fingerprint of the edits, used to cache the rendered result.
     */
    public function hash(): string
    {
        return substr(md5(json_encode([$this->crop, $this->rotate, $this->flipX, $this->flipY, $this->exposure, $this->contrast, $this->grayscale])), 0, 12);
    }

    /**
     * For the picker: "Cropped · Exposure +10".
     */
    public function describe(): ?string
    {
        $parts = array_filter([
            $this->crop ? 'Cropped'.($this->aspect ? ' '.str_replace('/', ':', $this->aspect) : '') : null,
            $this->rotate ? 'Rotated '.$this->rotate.'°' : null,
            $this->flipX || $this->flipY ? 'Flipped' : null,
            $this->exposure ? 'Exposure '.sprintf('%+d', $this->exposure) : null,
            $this->contrast ? 'Contrast '.sprintf('%+d', $this->contrast) : null,
            $this->grayscale ? 'Black & white' : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    protected static function fraction(mixed $value): float
    {
        return round(max(0.0, min(1.0, (float) $value)), 5);
    }
}
