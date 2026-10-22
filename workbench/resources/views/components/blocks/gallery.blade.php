@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $images = collect($data['images'] ?? [])
        ->filter(fn (mixed $image) => (is_string($image) && $image !== '') || \Aurora\Support\ImageEdits::assetId($image))
        ->map(fn (mixed $image) => ['thumb' => AssetLibrary::url($image, 'md'), 'full' => AssetLibrary::url($image, 'lg'), 'alt' => AssetLibrary::alt($image)])
        ->filter(fn (array $image) => $image['thumb'])
        ->values();
    $masonry = ($data['layout'] ?? 'grid') === 'masonry';
    $columns = match ((int) ($data['columns'] ?? 3)) {
        2 => $masonry ? 'columns-2' : 'grid-cols-2',
        4 => $masonry ? 'columns-2 @3xl:columns-4' : 'grid-cols-2 @3xl:grid-cols-4',
        default => $masonry ? 'columns-2 @3xl:columns-3' : 'grid-cols-2 @3xl:grid-cols-3',
    };
    $editing = is_editing();
@endphp

@if ($images->isEmpty())
    @if ($editing)
        <div class="grid aspect-[3/1] place-items-center rounded-3xl border-2 border-dashed border-line text-sm text-ink-soft transition hover:border-brand-400 hover:text-brand-600" {!! \Aurora\Blocks\InlineEditing::pickerAttributes('images') !!}>
            <span class="flex flex-col items-center gap-2">{{ svg('hero-o-photo', 'size-10 opacity-50') }} {{ __('Add images') }}</span>
        </div>
    @endif
@else
    <div
        class="@container"
        x-data="{
            images: @js($images->values()),
            active: null,
            opener: null,
            // The lightbox is a dialog: focus goes in, and back to the thumbnail on closing.
            open(index, opener) {
                this.opener = opener
                this.active = index
                this.$nextTick(() => this.$refs.close?.focus())
            },
            close() {
                if (this.active === null) return
                this.active = null
                this.opener?.focus()
            },
        }"
        x-on:keydown.escape.window="close()"
        x-on:keydown.arrow-right.window="active !== null && (active = (active + 1) % images.length)"
        x-on:keydown.arrow-left.window="active !== null && (active = (active - 1 + images.length) % images.length)"
    >
        <div @class(['gap-4', $columns, '[&>*]:mb-4' => $masonry, 'grid' => ! $masonry])>
            @foreach ($images as $index => $image)
                <button type="button" class="group block w-full overflow-hidden rounded-2xl focus-visible:outline-2 focus-visible:outline-brand-500" x-on:click="open({{ $index }}, $el)" aria-label="{{ __('Open image :number of :total', ['number' => $index + 1, 'total' => $images->count()]) }}">
                    <img src="{{ $image['thumb'] }}" alt="{{ $image['alt'] ?? '' }}" loading="lazy" @class(['w-full object-cover transition duration-500 group-hover:scale-105', 'aspect-square' => ! $masonry])>
                </button>
            @endforeach
        </div>

        <template x-teleport="body">
            <div x-show="active !== null" x-cloak x-transition.opacity role="dialog" aria-modal="true" aria-label="{{ __('Image') }}" x-on:keydown.tab.prevent="$refs.close.focus()" class="fixed inset-0 z-[100] grid place-items-center bg-zinc-950/90 p-6 backdrop-blur" x-on:click.self="close()">
                <img :src="images[active]?.full" :alt="images[active]?.alt ?? ''" class="max-h-[85vh] max-w-full rounded-2xl shadow-2xl">
                <button type="button" x-ref="close" class="absolute top-6 right-6 grid size-11 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20" x-on:click="close()" aria-label="{{ __('Close') }}">✕</button>
            </div>
        </template>
    </div>
@endif
