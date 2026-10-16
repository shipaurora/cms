{{--
    The per-block image editor (crop, rotate, flip, exposure, contrast, black & white).
    Its state is the stored value itself: {asset, crop, aspect, rotate, flip_x, flip_y, …}.
    The Alpine component lives in resources/js/admin/image-editor.js (Cropper.js).
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="cmsImageEditor({ state: $wire.$entangle(@js($getStatePath())), src: @js($src) })"
        class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]"
    >
        <div class="overflow-hidden rounded-xl bg-gray-950">
            <div class="h-[28rem]" :style="filterStyle">
                <img x-ref="image" src="{{ $src }}" alt="" class="block max-w-full" crossorigin="anonymous">
            </div>
        </div>

        <div class="space-y-5 text-sm">
            <div>
                <p class="mb-2 font-medium text-gray-950 dark:text-white">{{ __('Crop') }}</p>
                <div class="grid grid-cols-4 gap-1.5">
                    <template x-for="option in aspects" :key="option.value">
                        <button
                            type="button"
                            x-on:click="setAspect(option.value)"
                            x-text="option.value === null ? @js(__('Free')) : option.label"
                            :class="(state?.aspect ?? null) === option.value ? 'bg-primary-600 text-white ring-primary-600' : 'bg-white text-gray-700 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10'"
                            class="rounded-lg px-2 py-1.5 text-xs font-semibold ring-1 transition"
                        ></button>
                    </template>
                </div>
            </div>

            <div>
                <p class="mb-2 font-medium text-gray-950 dark:text-white">{{ __('Rotate & flip') }}</p>
                <div class="grid grid-cols-4 gap-1.5">
                    <x-filament::icon-button icon="phosphor-arrow-u-up-left-bold" color="gray" :label="__('Rotate left')" :tooltip="__('Rotate left')" x-on:click="rotate(-90)" />
                    <x-filament::icon-button icon="phosphor-arrow-u-up-right-bold" color="gray" :label="__('Rotate right')" :tooltip="__('Rotate right')" x-on:click="rotate(90)" />
                    <x-filament::icon-button icon="phosphor-arrows-left-right-bold" color="gray" :label="__('Flip horizontally')" :tooltip="__('Flip horizontally')" x-on:click="flip('x')" />
                    <x-filament::icon-button icon="phosphor-arrows-down-up-bold" color="gray" :label="__('Flip vertically')" :tooltip="__('Flip vertically')" x-on:click="flip('y')" />
                </div>
            </div>

            <label class="block">
                <span class="mb-1 flex justify-between font-medium text-gray-950 dark:text-white">{{ __('Exposure') }} <span class="font-mono text-xs text-gray-500" x-text="signed(state?.exposure)"></span></span>
                <input type="range" min="-100" max="100" step="1" x-model.number="state.exposure" class="w-full accent-primary-600">
            </label>

            <label class="block">
                <span class="mb-1 flex justify-between font-medium text-gray-950 dark:text-white">{{ __('Contrast') }} <span class="font-mono text-xs text-gray-500" x-text="signed(state?.contrast)"></span></span>
                <input type="range" min="-100" max="100" step="1" x-model.number="state.contrast" class="w-full accent-primary-600">
            </label>

            <label class="flex items-center gap-2 font-medium text-gray-950 dark:text-white">
                <input type="checkbox" x-model="state.grayscale" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                {{ __('Black & white') }}
            </label>

            <x-filament::button color="gray" size="sm" icon="phosphor-arrows-clockwise-bold" x-on:click="reset()" class="w-full">
                {{ __('Reset to original') }}
            </x-filament::button>
        </div>
    </div>
</x-dynamic-component>
