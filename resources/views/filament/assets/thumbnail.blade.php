@php
    /** @var \Aurora\Models\Asset $asset */
    $asset = isset($asset) ? $asset : $getRecord();
    $compact = $compact ?? false;
    $type = $asset->type();
    $extension = str($asset->file()?->file_name)->afterLast('.')->upper()->limit(5, '')->toString();
@endphp

{{-- Images show themselves; other files show their kind of file, and the extension. --}}
@if ($type === \Aurora\Enums\AssetType::Image && ($url = $asset->url('thumb')))
    <img
        src="{{ $url }}"
        alt=""
        loading="lazy"
        @class([
            'object-cover',
            'size-12 rounded-md' => $compact,
            'h-36 w-full rounded-lg' => ! $compact,
        ])
    >
@else
    <div
        @class([
            'cms-file-tile grid shrink-0 place-items-center text-primary-600 dark:text-primary-400',
            'size-12 rounded-md' => $compact,
            'h-36 w-full rounded-lg' => ! $compact,
        ])
        title="{{ $type->getLabel() }}"
    >
        <span class="flex flex-col items-center gap-1.5">
            <x-filament::icon :icon="$type->getIcon()" @class(['size-6' => $compact, 'size-12' => ! $compact]) />
            @if (! $compact && $extension !== '')
                <span class="font-mono text-[0.65rem] font-semibold tracking-widest text-gray-500 dark:text-gray-400">{{ $extension }}</span>
            @endif
        </span>
    </div>
@endif
