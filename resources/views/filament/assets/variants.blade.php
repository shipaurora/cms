@php
    /** @var \Aurora\Models\Asset $asset */
    $asset = isset($asset) ? $asset : $getRecord();
    $sizes = \Aurora\Support\AssetVariants::sizes($asset);
    $edits = \Aurora\Support\AssetVariants::edits($asset);
@endphp

{{-- A variant's thumbnail opens it full size. --}}
<div x-data="{ lightbox: null }" class="space-y-6">
    <p class="text-sm text-gray-500">{{ __('Every size the library makes from this image, and the versions cropped or adjusted for a single block.') }}</p>
    <ul class="divide-y divide-gray-950/5 dark:divide-white/5">
        @foreach ($sizes as $size)
            <li class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                @if ($size['url'])
                    <button type="button" x-on:click="lightbox = {{ \Illuminate\Support\Js::from(['src' => $size['url'], 'alt' => $size['name']]) }}" class="cms-lightbox-thumb" title="{{ __('View full size') }}" aria-label="{{ __('View full size') }}">
                        <img src="{{ $size['url'] }}" alt="" loading="lazy" class="size-12 rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10">
                    </button>
                @else
                    <span class="cms-format-placeholder"><x-filament::icon icon="phosphor-image" class="size-5" /></span>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                        {{-- Green: made. Yellow: being made. Red: missing. --}}
                        <span
                            @class(['cms-format-status', 'cms-format-status-'.$size['status']])
                            title="{{ match ($size['status']) { 'ready' => __('Made'), 'pending' => __('Being made'), default => __('Missing') } }}"
                            aria-label="{{ match ($size['status']) { 'ready' => __('Made'), 'pending' => __('Being made'), default => __('Missing') } }}"
                        ></span>
                        <span>{{ $size['name'] }}</span>
                        @if ($size['handle'])
                            <span class="cms-format-handle">({{ $size['handle'] }})</span>
                        @endif
                    </p>
                    <p class="truncate text-xs text-gray-500">{{ $size['purpose'] }}</p>
                </div>
                <div class="hidden shrink-0 text-end text-xs text-gray-500 sm:block">
                    <p class="font-medium text-gray-700 dark:text-gray-300">{{ $size['dimensions'] }}</p>
                    <p>{{ collect([$size['format'], $size['size']])->filter()->implode(' · ') }}</p>
                </div>
                @if ($size['handle'] && method_exists($this, 'regenerateFormatAction'))
                    {{ ($this->regenerateFormatAction)(['handle' => $size['handle']]) }}
                @endif
                @if ($size['url'])
                    <x-filament::icon-button tag="a" :href="$size['url']" target="_blank" icon="phosphor-arrow-square-out" color="gray" :label="__('Open')" :tooltip="__('Open')" />
                @endif
            </li>
        @endforeach
    </ul>

    @if ($edits !== [])
        <div>
            <p class="mb-3 text-xs font-semibold tracking-wider text-gray-500 uppercase">{{ __('Edited for a block') }}</p>
            <ul class="divide-y divide-gray-950/5 dark:divide-white/5">
                @foreach ($edits as $edit)
                    <li class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                        <button type="button" x-on:click="lightbox = {{ \Illuminate\Support\Js::from(['src' => $edit['url'], 'alt' => $edit['description']]) }}" class="cms-lightbox-thumb" title="{{ __('View full size') }}" aria-label="{{ __('View full size') }}">
                            <img src="{{ $edit['preview'] }}" alt="" loading="lazy" class="size-12 rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10">
                        </button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $edit['page'] }} · {{ $edit['block'] }}</p>
                            <p class="truncate text-xs text-primary-600 dark:text-primary-400">{{ $edit['description'] }}</p>
                        </div>
                        <x-filament::icon-button tag="a" :href="$edit['url']" target="_blank" icon="phosphor-arrow-square-out" color="gray" :label="__('Open')" :tooltip="__('Open')" />
                        <x-filament::icon-button tag="a" :href="$edit['editor']" icon="phosphor-paint-brush" color="gray" :label="__('Open in editor')" :tooltip="__('Open in editor')" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('aurora::filament.assets.lightbox')
</div>
