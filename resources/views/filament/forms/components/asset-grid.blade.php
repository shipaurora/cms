@php
    $statePath = $getStatePath();
    $multiple = $field->isMultiple();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{--
        Dropping files here uploads them to the library (Aurora\Filament\Forms\Components\AssetGrid)
        and selects them; the dialog stays open, to confirm with its own button.
    --}}
    <div
        x-data="{
            dragging: 0,
            uploading: false,
            async drop(event) {
                this.dragging = 0
                let files = [...(event.dataTransfer?.files ?? [])]

                if (! files.length || this.uploading) {
                    return
                }

                if (! @js($multiple)) {
                    files = files.slice(0, 1)
                }

                this.uploading = true

                try {
                    await new Promise((resolve, reject) => $wire.uploadMultiple(@js($field->getDroppedStatePath()), files, resolve, reject))
                    await $wire.callSchemaComponentMethod(@js($getKey()), 'storeDroppedFiles')
                } finally {
                    this.uploading = false
                }
            },
        }"
        x-on:dragenter.prevent="dragging++"
        x-on:dragover.prevent
        x-on:dragleave.prevent="dragging = Math.max(0, dragging - 1)"
        x-on:drop.prevent="drop($event)"
        class="relative"
    >
        @if ($assets->isEmpty())
            <div class="rounded-xl border-2 border-dashed border-gray-200 p-10 text-center text-sm text-gray-500 dark:border-white/10">
                {{ __('Nothing found. Drop a file here, or upload one in the Upload tab.') }}
            </div>
        @else
            <div class="grid max-h-[28rem] grid-cols-3 gap-3 overflow-y-auto p-0.5 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($assets as $asset)
                    <label class="group relative cursor-pointer" wire:key="pick-{{ $asset->id }}">
                        <input
                            type="{{ $multiple ? 'checkbox' : 'radio' }}"
                            value="{{ $asset->id }}"
                            wire:model.live="{{ $statePath }}"
                            @unless ($multiple)
                                {{-- One image: choosing it is the whole job, so use it straight away. --}}
                                x-on:change="$wire.callMountedAction()"
                            @endunless
                            class="peer sr-only"
                        >
                        <span class="cms-pick-thumb block overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-950/10 transition peer-checked:ring-2 peer-checked:ring-primary-500 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500 dark:bg-white/5 dark:ring-white/10">
                            @if ($asset->isImage())
                                <img src="{{ $asset->url('thumb') }}" alt="" class="aspect-[4/3] w-full object-cover transition group-hover:scale-105" loading="lazy">
                            @else
                                <span class="block aspect-[4/3] [&>div]:!h-full">@include('aurora::filament.assets.thumbnail', ['asset' => $asset])</span>
                            @endif
                        </span>
                        <span class="absolute top-1.5 right-1.5 hidden size-5 place-items-center rounded-full bg-primary-600 text-white shadow peer-checked:grid">
                            <x-filament::icon icon="phosphor-check-bold" class="size-3.5" />
                        </span>
                        <span class="mt-1 block truncate text-xs text-gray-700 dark:text-gray-300">{{ $asset->title }}</span>
                    </label>
                @endforeach
            </div>
        @endif

        {{-- Over the grid while a file is dragged over it, and while it uploads. --}}
        <div
            x-show="dragging > 0 || uploading"
            x-cloak
            class="pointer-events-none absolute inset-0 z-10 grid place-items-center rounded-xl border-2 border-dashed border-primary-500 bg-primary-50/90 text-sm font-medium text-primary-700 dark:bg-primary-950/80 dark:text-primary-300"
        >
            <span class="inline-flex items-center gap-2">
                <span x-show="! uploading">{{ svg('phosphor-upload-simple', 'size-5') }}</span>
                <span x-show="uploading" x-cloak>{{ svg('phosphor-circle-notch', 'size-5 animate-spin') }}</span>
                <span x-text="uploading ? @js(__('Uploading…')) : @js(__('Drop to upload'))"></span>
            </span>
        </div>
    </div>
</x-dynamic-component>
