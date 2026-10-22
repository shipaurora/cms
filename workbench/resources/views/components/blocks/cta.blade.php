@props(['data' => []])

@php
    $style = $data['style'] ?? 'gradient';
    $dark = $style !== 'light';
    $href = is_editing() ? '#' : (link_href($data, 'button') ?? '#');
@endphp

<div @class([
    'relative isolate overflow-hidden rounded-[2rem] px-8 py-16 text-center @container @3xl:px-16 @3xl:py-20',
    'tone-dark' => $dark,
    'tone-light bg-brand-50 ring-1 ring-brand-100' => ! $dark,
    'bg-aurora' => $style === 'gradient',
    'bg-brand-600' => $style === 'brand',
    'bg-zinc-950' => $style === 'dark',
])>
    @if ($dark)
        <div class="absolute inset-0 -z-10 bg-grid opacity-60"></div>
        <div class="pointer-events-none absolute -top-24 left-1/2 -z-10 h-64 w-[40rem] -translate-x-1/2 rounded-full bg-brand-400/30 blur-3xl"></div>
    @endif

    <h2 class="mx-auto max-w-2xl font-display text-3xl font-semibold tracking-tight text-balance text-ink @3xl:text-5xl" @editable('title')>{{ inline_text($data['title'] ?? '') }}</h2>

    @if (shows_field('text', $data['text'] ?? null))
        <p class="mx-auto mt-5 max-w-xl text-lg/8 text-ink-muted whitespace-pre-line" @editable('text')>{{ plain_text($data['text'] ?? '') }}</p>
    @endif

    @if (shows_field('button_label', $data['button_label'] ?? null))
        <a href="{{ $href }}" @if (link_target($data, 'button')) target="_blank" rel="noopener" @endif @class([
            'group mt-10 inline-flex items-center gap-2 rounded-full px-7 py-4 font-semibold shadow-xl transition',
            'bg-white text-zinc-900 hover:bg-brand-50' => $dark,
            'bg-brand-600 text-white shadow-brand-600/30 hover:bg-brand-500' => ! $dark,
        ])>
            <span @editable('button_label')>{{ plain_text($data['button_label'] ?? '') }}</span>
            {{ svg('hero-m-arrow-right', 'size-4 transition group-hover:translate-x-1') }}
        </a>
    @endif
</div>
