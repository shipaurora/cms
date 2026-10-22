@props(['data' => []])

@php
    use Aurora\Support\SiteIcon;

    $href = link_href($data) ?? '#';
    $target = link_target($data);
    $variant = $data['variant'] ?? 'primary';
    $icon = SiteIcon::name($data['icon'] ?? null, style: 'm');
    $editing = is_editing();

    $size = match ($data['size'] ?? 'md') {
        'sm' => 'px-3.5 py-2 text-sm',
        'lg' => 'px-7 py-4 text-base',
        default => 'px-5 py-3 text-sm',
    };

    $style = match ($variant) {
        'secondary' => 'bg-zinc-900 text-white hover:bg-zinc-700 [.tone-dark_&]:bg-white [.tone-dark_&]:text-zinc-900 [.tone-dark_&]:hover:bg-zinc-100',
        'outline' => 'ring-1 ring-inset ring-line text-ink hover:bg-surface hover:ring-ink-soft',
        'ghost' => 'text-ink hover:bg-zinc-900/5 [.tone-dark_&]:hover:bg-white/10',
        'link' => 'px-0! text-brand-600 hover:text-brand-500 [.tone-dark_&]:text-brand-300',
        default => 'bg-brand-600 text-white shadow-lg shadow-brand-600/25 hover:bg-brand-500 hover:shadow-brand-500/40',
    };
@endphp

<a
    href="{{ $editing ? '#' : $href }}"
    @if ($target) target="{{ $target }}" rel="noopener" @endif
    class="group inline-flex items-center justify-center gap-2 rounded-full font-semibold transition duration-200 {{ $size }} {{ $style }}"
><span @editable('label')>{{ plain_text($data['label'] ?? '') }}</span>@if ($icon){{ svg($icon, 'size-4 transition group-hover:translate-x-0.5') }}@elseif ($variant === 'link')<span aria-hidden="true" class="transition group-hover:translate-x-0.5">→</span>@endif</a>
