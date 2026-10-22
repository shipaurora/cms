@props(['data' => []])

@php
    use Workbench\App\Blocks\HeadingBlock;

    $level = in_array($data['level'] ?? null, HeadingBlock::LEVELS, true) ? $data['level'] : 'h2';
    $centered = ($data['align'] ?? 'left') === 'center';

    $size = match ($data['size'] ?? 'lg') {
        'display' => 'text-5xl @3xl:text-7xl tracking-tight',
        'xl' => 'text-4xl @3xl:text-5xl tracking-tight',
        'md' => 'text-2xl @3xl:text-3xl tracking-tight',
        'sm' => 'text-xl',
        default => 'text-3xl @3xl:text-4xl tracking-tight',
    };
@endphp

<div @class(['@container', 'text-center mx-auto max-w-3xl' => $centered, 'max-w-3xl' => ! $centered])>
    @if (shows_field('eyebrow', $data['eyebrow'] ?? null))
        <p class="mb-3 text-sm font-semibold tracking-widest text-brand-600 uppercase [.tone-dark_&]:text-brand-300" @editable('eyebrow')>{{ plain_text($data['eyebrow'] ?? '') }}</p>
    @endif

    <{{ $level }} class="font-display font-semibold text-balance text-ink {{ $size }} whitespace-pre-line" @editable('text')>{{ inline_text($data['text'] ?? '') }}</{{ $level }}>

    @if (shows_field('intro', $data['intro'] ?? null))
        <p class="mt-5 text-lg/8 text-pretty text-ink-muted whitespace-pre-line" @editable('intro')>{{ plain_text($data['intro'] ?? '') }}</p>
    @endif
</div>
