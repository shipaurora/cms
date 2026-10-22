@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $image = AssetLibrary::url($data['image'] ?? null);
    $editing = is_editing();
    $href = link_href($data);
    $style = $data['style'] ?? 'elevated';
    $tag = $href && ! $editing ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($tag === 'a') href="{{ $href }}" @if (link_target($data)) target="_blank" rel="noopener" @endif @endif
    @class([
        'group flex h-full flex-col overflow-hidden rounded-3xl transition duration-300',
        'tone-light bg-white shadow-sm ring-1 ring-zinc-900/5 hover:-translate-y-1 hover:shadow-xl hover:shadow-zinc-900/10' => $style === 'elevated',
        'ring-1 ring-line bg-surface hover:ring-brand-400' => $style === 'bordered',
        'bg-white/5 ring-1 ring-white/10 backdrop-blur-xl tone-dark' => $style === 'glass',
    ])
>
    @if ($image)
        <div class="aspect-[16/10] overflow-hidden">
            <x-aurora::media :value="$data['image']" size="md" sizes="(min-width: 1024px) 33vw, 100vw" class="size-full object-cover transition duration-500 group-hover:scale-105" />
        </div>
    @endif

    <div @class(['flex flex-1 flex-col', 'p-6 @3xl:p-7' => $style !== 'ghost', 'pt-4' => $style === 'ghost' && $image])>
        @if (shows_field('badge', $data['badge'] ?? null))
            <span class="mb-3 w-fit rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-100" @editable('badge')>{{ plain_text($data['badge'] ?? '') }}</span>
        @endif
        <h3 class="font-display text-xl font-semibold text-ink" @editable('title')>{{ inline_text($data['title'] ?? '') }}</h3>
        @if (shows_field('body', $data['body'] ?? null))
            <p class="mt-2 flex-1 text-base/7 text-ink-muted whitespace-pre-line" @editable('body')>{{ plain_text($data['body'] ?? '') }}</p>
        @endif
        @if (($href || $editing) && shows_field('link_label', $data['link_label'] ?? null))
            <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 [.tone-dark_&]:text-brand-300">
                <span @editable('link_label')>{{ plain_text($data['link_label'] ?? '') }}</span>
                {{ svg('hero-m-arrow-right', 'size-4 transition group-hover:translate-x-1') }}
            </span>
        @endif
    </div>
</{{ $tag }}>
