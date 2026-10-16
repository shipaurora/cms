@php
    /** @var \Aurora\Models\Asset $asset */
    $asset = $getRecord();
    $type = $asset->type();
@endphp

@if ($type === \Aurora\Enums\AssetType::Image)
    {{-- Click the image to see it full size. --}}
    <div
        x-data="{ lightbox: null }"
        class="overflow-hidden rounded-xl bg-[repeating-conic-gradient(#f4f4f5_0_25%,#fff_0_50%)] bg-[length:20px_20px] dark:bg-[repeating-conic-gradient(#18181b_0_25%,#27272a_0_50%)]"
    >
        <button type="button" x-on:click="lightbox = {{ \Illuminate\Support\Js::from(['src' => $asset->url(null), 'alt' => $asset->alt]) }}" class="cms-lightbox-trigger" title="{{ __('View full size') }}" aria-label="{{ __('View full size') }}">
            <img src="{{ $asset->url('lg') }}" alt="{{ $asset->alt }}" class="mx-auto max-h-[28rem] w-auto object-contain">
        </button>

        @include('aurora::filament.assets.lightbox')
    </div>
@elseif ($type === \Aurora\Enums\AssetType::Video)
    <video src="{{ $asset->url(null) }}" controls preload="metadata" class="mx-auto max-h-[28rem] w-full rounded-xl bg-black"></video>
@elseif ($type === \Aurora\Enums\AssetType::Audio)
    <div class="cms-file-tile flex flex-col items-center gap-4 rounded-xl p-8 text-primary-600 dark:text-primary-400">
        <x-filament::icon :icon="$type->getIcon()" class="size-14" />
        <audio src="{{ $asset->url(null) }}" controls preload="metadata" class="w-full max-w-md"></audio>
    </div>
@else
    {{-- Other files: what kind of file it is, and a way to open it. --}}
    <div class="cms-file-tile flex flex-col items-center gap-3 rounded-xl p-10 text-center">
        <x-filament::icon :icon="$type->getIcon()" class="size-16 text-primary-600 dark:text-primary-400" />
        <div>
            <p class="font-semibold text-gray-950 dark:text-white">{{ $asset->file()?->file_name }}</p>
            <p class="text-sm text-gray-500">{{ $type->getLabel() }} · {{ $asset->file() ? \Illuminate\Support\Number::fileSize($asset->file()->size, 1) : '' }}</p>
        </div>
        <x-filament::button tag="a" :href="$asset->url(null)" target="_blank" color="gray" size="sm" icon="phosphor-arrow-square-out-bold">
            {{ __('Open file') }}
        </x-filament::button>
    </div>
@endif
