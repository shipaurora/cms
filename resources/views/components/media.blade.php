{{--
    An image from the media library (or a legacy path/URL), responsive:
    <x-aurora::media :value="$data['image']" size="lg" sizes="(min-width: 1024px) 50vw, 100vw" class="…" />
    Alt text comes from the asset unless the caller passes its own. Images load lazily
    unless the caller says otherwise, e.g. loading="eager" fetchpriority="high" for a hero.
--}}
@props(['value' => null, 'size' => 'md', 'sizes' => '100vw', 'alt' => null])

@php
    use Aurora\Support\AssetLibrary;

    $asset = AssetLibrary::find($value);
    $edited = $asset && ! \Aurora\Support\ImageEdits::from($value)->isEmpty();
    $src = AssetLibrary::url($value, $size);
    $srcset = AssetLibrary::srcset($value);
    // An edited (cropped or rotated) image no longer has the original's proportions.
    $dimensions = $edited ? ['width' => null, 'height' => null] : ($asset?->dimensions() ?? ['width' => null, 'height' => null]);
@endphp

@if ($src)
    <img
        src="{{ $src }}"
        @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        alt="{{ $alt ?? $asset?->alt ?? '' }}"
        @if ($dimensions['width']) width="{{ $dimensions['width'] }}" height="{{ $dimensions['height'] }}" @endif
        {{ $attributes->merge(['loading' => 'lazy', 'decoding' => 'async']) }}
    >
@endif
