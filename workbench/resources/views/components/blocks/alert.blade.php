@props(['data' => []])

@php
    $variant = $data['variant'] ?? 'info';

    [$classes, $icon] = match ($variant) {
        'success' => ['bg-emerald-50 ring-emerald-200 text-emerald-900 [&_.alert-icon]:text-emerald-600', 'check-circle'],
        'warning' => ['bg-amber-50 ring-amber-200 text-amber-900 [&_.alert-icon]:text-amber-600', 'exclamation-triangle'],
        'danger' => ['bg-rose-50 ring-rose-200 text-rose-900 [&_.alert-icon]:text-rose-600', 'x-circle'],
        'brand' => ['bg-brand-50 ring-brand-200 text-brand-950 [&_.alert-icon]:text-brand-600', 'sparkles'],
        default => ['bg-sky-50 ring-sky-200 text-sky-900 [&_.alert-icon]:text-sky-600', 'information-circle'],
    };
@endphp

<div class="tone-light flex gap-4 rounded-2xl p-5 ring-1 ring-inset {{ $classes }}" role="note">
    {{ svg('hero-s-'.$icon, 'alert-icon size-6 shrink-0') }}
    <div class="min-w-0 flex-1">
        @if (shows_field('title', $data['title'] ?? null))
            <p class="font-semibold" @editable('title')>{{ plain_text($data['title'] ?? '') }}</p>
        @endif
        @if (shows_field('body', $data['body'] ?? null))
            <p class="mt-1 text-sm/6 whitespace-pre-line opacity-90" @editable('body')>{{ plain_text($data['body'] ?? '') }}</p>
        @endif
    </div>
</div>
