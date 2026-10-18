@php
    /** @var \Aurora\Models\Asset $asset */
    $asset = $getRecord();
    $statePath = $getStatePath();
@endphp

{{-- Where cropped sizes of the image keep its subject: click it on the image. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($statePath)),
            pick(event) {
                const box = event.currentTarget.getBoundingClientRect()
                this.state = {
                    x: Math.round(((event.clientX - box.left) / box.width) * 1000) / 1000,
                    y: Math.round(((event.clientY - box.top) / box.height) * 1000) / 1000,
                }
            },
        }"
        class="cms-focal-point"
    >
        <button type="button" class="cms-focal-point-image" x-on:click="pick($event)" title="{{ __('Click where the subject is') }}">
            <img src="{{ $asset->url('lg') }}" alt="" draggable="false">
            <span
                class="cms-focal-point-dot"
                x-show="state"
                x-bind:style="state && { left: (state.x * 100) + '%', top: (state.y * 100) + '%' }"
                x-cloak
            ></span>
        </button>
        <p class="cms-focal-point-hint">
            <span x-show="! state">{{ __('Click where the subject is. Cropped sizes (the thumbnail, the share image, formats with a height) keep it in.') }}</span>
            <span x-show="state" x-cloak>
                {{ __('Cropped sizes keep this point in. They’re made again when you save.') }}
                <button type="button" class="cms-focal-point-reset" x-on:click="state = null">{{ __('Back to the middle') }}</button>
            </span>
        </p>
    </div>
</x-dynamic-component>
