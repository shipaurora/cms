@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $avatar = AssetLibrary::url($data['avatar'] ?? null, 'thumb');
    $rating = max(0, min(5, (int) ($data['rating'] ?? 0)));
    $large = ($data['style'] ?? 'card') === 'large';
    $initials = collect(explode(' ', (string) ($data['name'] ?? '')))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('');
@endphp

<figure @class([
    'flex h-full flex-col',
    'mx-auto max-w-4xl text-center' => $large,
    'rounded-3xl bg-surface p-8 ring-1 ring-line' => ! $large,
])>
    @if ($rating > 0)
        <div @class(['flex gap-0.5 text-amber-400', 'justify-center' => $large])>
            @for ($i = 0; $i < $rating; $i++)
                {{ svg('hero-s-star', 'size-5') }}
            @endfor
        </div>
    @endif

    <blockquote @class([
        'mt-6 flex-1 text-ink whitespace-pre-line text-pretty',
        'font-display text-3xl/[1.3] font-medium tracking-tight @3xl:text-4xl/[1.25]' => $large,
        'text-lg/8' => ! $large,
    ])>
        <p @editable('quote')>“{{ plain_text($data['quote'] ?? '') }}”</p>
    </blockquote>

    <figcaption @class(['mt-8 flex items-center gap-4', 'justify-center' => $large])>
        @if ($avatar)
            <img src="{{ $avatar }}" alt="" class="size-12 rounded-full object-cover ring-2 ring-white/80">
        @else
            <span class="grid size-12 place-items-center rounded-full bg-gradient-to-br from-brand-500 to-accent-400 text-sm font-semibold text-white">{{ $initials }}</span>
        @endif
        <div class="text-left">
            <p class="font-semibold text-ink" @editable('name')>{{ plain_text($data['name'] ?? '') }}</p>
            <p class="text-sm text-ink-soft" @editable('role')>{{ plain_text($data['role'] ?? '') }}</p>
        </div>
    </figcaption>
</figure>
