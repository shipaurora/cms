@props(['data' => []])

@php
    use Aurora\Blocks\InlineEditing;

    $featured = (bool) ($data['featured'] ?? false);
    $features = array_values(array_filter($data['features'] ?? [], fn ($item) => is_array($item) || is_string($item)));
    $href = is_editing() ? '#' : (link_href($data, 'button') ?? '#');
@endphp

<div @class([
    'relative flex h-full flex-col rounded-3xl p-8 transition duration-300',
    'tone-dark bg-zinc-950 shadow-2xl shadow-brand-900/30 ring-1 ring-brand-500/40 @5xl:-my-4 @5xl:py-12' => $featured,
    'tone-light bg-white ring-1 ring-zinc-200 hover:ring-brand-300' => ! $featured,
])>
    @if ($featured)
        <div class="pointer-events-none absolute inset-x-0 -top-px mx-auto h-px w-2/3 bg-gradient-to-r from-transparent via-brand-400 to-transparent"></div>
        @if (shows_field('badge', $data['badge'] ?? null))
            <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-gradient-to-r from-brand-600 to-accent-500 px-3.5 py-1 text-xs font-semibold whitespace-nowrap text-white shadow-lg" @editable('badge')>{{ plain_text($data['badge'] ?? '') }}</span>
        @endif
    @endif

    <h3 class="font-display text-lg font-semibold text-ink" @editable('name')>{{ plain_text($data['name'] ?? '') }}</h3>
    @if (shows_field('description', $data['description'] ?? null))
        <p class="mt-2 text-sm/6 text-ink-muted" @editable('description')>{{ plain_text($data['description'] ?? '') }}</p>
    @endif

    <p class="mt-6 flex items-baseline gap-1">
        <span class="text-2xl font-semibold text-ink-muted">{{ $data['currency'] ?? '' }}</span>
        <span class="font-display text-5xl font-semibold tracking-tight text-ink" @editable('price')>{{ plain_text($data['price'] ?? '') }}</span>
        <span class="text-sm text-ink-soft" @editable('period')>{{ plain_text($data['period'] ?? '') }}</span>
    </p>

    <a href="{{ $href }}" @class([
        'mt-8 block rounded-full px-4 py-3 text-center text-sm font-semibold transition',
        'bg-brand-600 text-white shadow-lg shadow-brand-600/30 hover:bg-brand-500' => $featured,
        'bg-zinc-900/5 text-zinc-900 hover:bg-zinc-900/10' => ! $featured,
    ])><span @editable('button_label')>{{ plain_text($data['button_label'] ?? '') }}</span></a>

    <ul class="mt-8 space-y-3 text-sm/6">
        @foreach ($features as $index => $feature)
            <li class="flex gap-3">
                {{ svg('hero-m-check-circle', 'size-5 shrink-0 '.($featured ? 'text-brand-400' : 'text-brand-600')) }}
                <span class="text-ink-muted" {!! InlineEditing::itemAttributes('features', $index, 'text') !!}>{{ is_array($feature) ? ($feature['text'] ?? '') : $feature }}</span>
            </li>
        @endforeach
    </ul>
</div>
