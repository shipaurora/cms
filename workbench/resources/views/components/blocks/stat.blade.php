@props(['data' => []])

@php
    $editing = is_editing();
    $value = (string) ($data['value'] ?? '');
    $numeric = (float) str_replace([',', ' '], '', $value);
    $decimals = str_contains($value, '.') ? strlen(substr(strrchr($value, '.'), 1)) : 0;
    // Counts up to finite numbers only, formatted the way the page's language writes them.
    $animate = ! $editing && ($data['animate'] ?? true) && is_numeric(str_replace([',', ' '], '', $value)) && is_finite($numeric);
@endphp

<div class="h-full border-l-2 border-brand-500/60 pl-6">
    <p class="font-display text-5xl font-semibold tracking-tight text-ink tabular-nums @3xl:text-6xl">
        @if (filled($data['prefix'] ?? null))<span class="text-gradient">{{ $data['prefix'] }}</span>@endif<span
            @editable('value')
            @if ($animate)
                x-data="{ value: 0, run() { const end = {{ Js::from($numeric) }}; if (matchMedia('(prefers-reduced-motion: reduce)').matches) { this.value = end; return; } const start = performance.now(), d = 1600; const tick = (t) => { const p = Math.min(1, (t - start) / d); this.value = end * (1 - Math.pow(1 - p, 4)); if (p < 1) requestAnimationFrame(tick); }; requestAnimationFrame(tick); } }"
                x-intersect.once="run()"
                x-text="value.toLocaleString({{ Js::from(\Aurora\Support\ContentLocale::current()) }}, { minimumFractionDigits: {{ $decimals }}, maximumFractionDigits: {{ $decimals }} })"
            @endif
        >{{ $value }}</span>@if (filled($data['suffix'] ?? null))<span class="text-gradient">{{ $data['suffix'] }}</span>@endif
    </p>
    <p class="mt-2 font-semibold text-ink" @editable('label')>{{ plain_text($data['label'] ?? '') }}</p>
    @if (shows_field('description', $data['description'] ?? null))
        <p class="mt-1 text-sm/6 text-ink-soft" @editable('description')>{{ plain_text($data['description'] ?? '') }}</p>
    @endif
</div>
