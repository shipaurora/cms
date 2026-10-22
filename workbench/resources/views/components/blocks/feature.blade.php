@props(['data' => []])

@php
    use Aurora\Blocks\Fields;
    use Aurora\Support\SiteIcon;

    $icon = SiteIcon::name($data['icon'] ?? null) ?? 'hero-o-sparkles';
    $style = $data['style'] ?? 'soft';
    $tone = array_key_exists($data['tone'] ?? '', Fields::TONES) ? $data['tone'] : 'brand';

    $palette = match ($tone) {
        'accent' => ['soft' => 'bg-accent-50 text-accent-700 ring-accent-100', 'solid' => 'bg-accent-500 text-white shadow-accent-500/30'],
        'neutral' => ['soft' => 'bg-zinc-100 text-zinc-700 ring-zinc-200', 'solid' => 'bg-zinc-700 text-white shadow-zinc-700/30'],
        'dark' => ['soft' => 'bg-zinc-900 text-white ring-zinc-800', 'solid' => 'bg-zinc-950 text-white shadow-zinc-950/30'],
        'success' => ['soft' => 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'solid' => 'bg-emerald-500 text-white shadow-emerald-500/30'],
        'warning' => ['soft' => 'bg-amber-50 text-amber-700 ring-amber-100', 'solid' => 'bg-amber-500 text-white shadow-amber-500/30'],
        'danger' => ['soft' => 'bg-rose-50 text-rose-700 ring-rose-100', 'solid' => 'bg-rose-500 text-white shadow-rose-500/30'],
        default => ['soft' => 'bg-brand-50 text-brand-700 ring-brand-100', 'solid' => 'bg-brand-600 text-white shadow-brand-600/30'],
    };

    $iconClasses = match ($style) {
        'solid' => 'shadow-lg '.$palette['solid'],
        'outline' => 'ring-1 ring-line text-ink',
        default => 'ring-1 '.$palette['soft'],
    };
@endphp

<div @class([
    'group h-full',
    'rounded-3xl bg-surface p-7 ring-1 ring-line transition duration-300 hover:ring-brand-300 hover:shadow-xl hover:shadow-brand-900/5' => $style === 'card',
])>
    <div class="grid size-12 place-items-center rounded-2xl transition duration-300 group-hover:scale-110 group-hover:-rotate-3 {{ $style === 'card' ? 'ring-1 '.$palette['soft'] : $iconClasses }}">
        {{ svg($icon, 'size-6') }}
    </div>
    <h3 class="mt-5 font-display text-lg font-semibold text-ink" @editable('title')>{{ plain_text($data['title'] ?? '') }}</h3>
    @if (shows_field('body', $data['body'] ?? null))
        <p class="mt-2 text-base/7 text-ink-muted whitespace-pre-line" @editable('body')>{{ plain_text($data['body'] ?? '') }}</p>
    @endif
</div>
