@php
    $items = $getItems();
    $legacy = $getLegacyValue();
    $multiple = $isMultiple();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="space-y-2">
        @if ($items !== [] || $legacy)
            <div @class(['grid gap-2', 'grid-cols-2' => $multiple])>
                @foreach ($items as $item)
                    @php($description = $item['edits']->describe())
                    <div class="group relative overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10" wire:key="{{ $getStatePath() }}-{{ $item['index'] }}-{{ $item['asset']->id }}">
                        @if ($item['asset']->isImage())
                            <img src="{{ $item['preview'] }}" alt="" class="{{ $multiple ? 'aspect-square' : 'aspect-[4/3]' }} w-full bg-[repeating-conic-gradient(#e4e4e7_0_25%,#fff_0_50%)] bg-[length:16px_16px] object-contain">
                        @else
                            <div class="flex items-center gap-3 p-2.5">@include('aurora::filament.assets.thumbnail', ['asset' => $item['asset'], 'compact' => true])<span class="text-xs text-gray-500">{{ $item['asset']->type()->getLabel() }}</span></div>
                        @endif

                        <div class="flex items-center justify-between gap-2 border-t border-gray-950/5 px-2.5 py-1.5 dark:border-white/10">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-medium text-gray-950 dark:text-white">{{ $item['asset']->title }}</p>
                                @if ($description)
                                    <p class="truncate text-xs text-primary-600 dark:text-primary-400">{{ $description }}</p>
                                @elseif (! $multiple && $item['asset']->isImage())
                                    <p @class(['truncate text-xs', 'text-gray-500' => $item['asset']->alt, 'text-warning-600' => ! $item['asset']->alt])>{{ $item['asset']->alt ?: __('No alt text yet') }}</p>
                                @endif
                            </div>
                            @if ($item['asset']->isImage())
                                {{ ($getAction('adjustAsset'))(['index' => $item['index']])->iconButton()->tooltip(__('Crop and adjust for this block')) }}
                            @endif
                        </div>

                        <button
                            type="button"
                            wire:click="callSchemaComponentMethod('{{ $getKey() }}', 'removeAsset', { index: {{ $item['index'] }} })"
                            class="absolute top-1.5 right-1.5 grid size-6 place-items-center rounded-full bg-gray-950/70 text-white opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
                            title="{{ __('Remove') }}"
                            aria-label="{{ __('Remove') }}"
                        >
                            <x-filament::icon icon="phosphor-x-bold" class="size-3.5" />
                        </button>
                    </div>
                @endforeach

                @if ($legacy)
                    <div class="overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10">
                        <img src="{{ \Aurora\Support\AssetLibrary::url($legacy) }}" alt="" class="aspect-[4/3] w-full object-cover">
                        <p class="truncate px-2.5 py-1.5 text-xs text-gray-500">{{ __('Not in the media library: :file', ['file' => basename($legacy)]) }}</p>
                    </div>
                @endif
            </div>
        @endif

        {{ $getAction('pickAsset') }}
    </div>
</x-dynamic-component>
