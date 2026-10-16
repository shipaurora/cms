{{--
    An image full size over the page. Goes inside an x-data with `lightbox` (the image to show,
    or null): `x-on:click="lightbox = { src: '…', alt: '…' }"` opens it. Moved to the end of the
    page, so the panels' blur can't clip it; Esc, a click or the close button closes it.
--}}
<template x-teleport="body">
    <div
        x-show="lightbox"
        x-transition.opacity.duration.150ms
        x-on:click="lightbox = null"
        x-on:keydown.escape.window="lightbox = null"
        x-trap.noscroll="lightbox"
        class="cms-lightbox"
        role="dialog"
        aria-modal="true"
        x-cloak
    >
        <img x-bind:src="lightbox?.src" x-bind:alt="lightbox?.alt ?? ''" class="cms-lightbox-image">
        <button type="button" x-on:click.stop="lightbox = null" class="cms-lightbox-close" title="{{ __('Close') }}" aria-label="{{ __('Close') }}">
            <x-filament::icon icon="phosphor-x-bold" class="size-5" />
        </button>
    </div>
</template>
