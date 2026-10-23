@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $logos = array_values(array_filter($data['logos'] ?? [], 'is_array'));
    $editing = is_editing();
    $marquee = ! $editing && ($data['marquee'] ?? true) && count($logos) > 3;
    $fonts = ['font-display font-bold tracking-tight', 'font-serif italic font-semibold', 'font-mono font-semibold uppercase tracking-widest text-base', 'font-display font-extrabold uppercase tracking-tighter', 'font-sans font-light tracking-[0.2em] uppercase text-base', 'font-display font-semibold'];
@endphp

<div class="@container">
    @if (shows_field('title', $data['title'] ?? null))
        <p class="text-center text-sm font-semibold text-ink-soft" @editable('title')>{{ plain_text($data['title'] ?? '') }}</p>
    @endif

    <div @class(['mt-8', 'relative overflow-hidden [mask-image:linear-gradient(to_right,transparent,black_12%,black_88%,transparent)]' => $marquee])>
        <div @class(['flex items-center', 'w-max animate-marquee gap-16 hover:[animation-play-state:paused]' => $marquee, 'flex-wrap justify-center gap-x-14 gap-y-8' => ! $marquee])>
            @foreach ($marquee ? [...$logos, ...$logos] : $logos as $index => $logo)
                @php $image = AssetLibrary::url($logo['image'] ?? null, 'sm'); @endphp
                <div class="flex shrink-0 items-center text-ink-soft opacity-80 grayscale transition hover:opacity-100 hover:grayscale-0" @if ($marquee && $index >= count($logos)) aria-hidden="true" @endif>
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $logo['name'] ?? '' }}" class="h-8 w-auto">
                    @else
                        <span class="text-xl whitespace-nowrap {{ $fonts[$index % count($fonts)] }}" @unless ($marquee) @editableItem('logos', $index, 'name') @endunless>{{ $logo['name'] ?? '' }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
