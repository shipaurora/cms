@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $photo = AssetLibrary::url($data['photo'] ?? null, 'sm');
@endphp

<div class="group">
    <div class="relative aspect-square overflow-hidden rounded-3xl bg-gradient-to-br from-brand-100 to-accent-100">
        @if ($photo)
            <img src="{{ $photo }}" alt="{{ $data['name'] ?? '' }}" class="size-full object-cover grayscale transition duration-500 group-hover:scale-105 group-hover:grayscale-0" loading="lazy">
        @else
            <div class="grid size-full place-items-center" {!! \Aurora\Blocks\InlineEditing::pickerAttributes('photo') !!}>{{ svg('hero-o-user', 'size-16 text-brand-300') }}</div>
        @endif
        @if (filled($data['email'] ?? null) || filled($data['linkedin'] ?? null))
            <div class="absolute inset-x-3 bottom-3 flex translate-y-4 justify-center gap-2 opacity-0 transition duration-300 group-hover:translate-y-0 group-hover:opacity-100">
                @if (filled($data['email'] ?? null))
                    <a href="mailto:{{ $data['email'] }}" class="grid size-9 place-items-center rounded-full bg-white/90 text-zinc-900 shadow backdrop-blur hover:bg-white" aria-label="{{ __('Email') }}">{{ svg('hero-m-envelope', 'size-4') }}</a>
                @endif
                @if (filled($data['linkedin'] ?? null))
                    <a href="{{ $data['linkedin'] }}" target="_blank" rel="noopener" class="grid size-9 place-items-center rounded-full bg-white/90 text-xs font-bold text-zinc-900 shadow backdrop-blur hover:bg-white" aria-label="{{ __('LinkedIn') }}">in</a>
                @endif
            </div>
        @endif
    </div>
    <h3 class="mt-5 font-display text-lg font-semibold text-ink" @editable('name')>{{ plain_text($data['name'] ?? '') }}</h3>
    <p class="text-sm font-medium text-brand-600 [.tone-dark_&]:text-brand-300" @editable('role')>{{ plain_text($data['role'] ?? '') }}</p>
    @if (shows_field('bio', $data['bio'] ?? null))
        <p class="mt-3 text-sm/6 text-ink-muted" @editable('bio')>{{ plain_text($data['bio'] ?? '') }}</p>
    @endif
</div>
