@props(['data' => []])

@php
    use Aurora\Support\AssetLibrary;

    $layout = in_array($data['layout'] ?? null, ['centered', 'split', 'cover'], true) ? $data['layout'] : 'centered';
    $image = AssetLibrary::url($data['image'] ?? null, 'lg');
    $editing = is_editing();
    $dark = $layout !== 'split';

    $actions = [];
    foreach (['primary', 'secondary'] as $action) {
        if (shows_field("{$action}_label", $data["{$action}_label"] ?? null)) {
            $actions[$action] = [
                'href' => $editing ? '#' : (link_href($data, $action) ?? '#'),
                'target' => link_target($data, $action),
            ];
        }
    }
@endphp

<section @class([
    'relative isolate overflow-hidden @container',
    'tone-dark bg-zinc-950' => $dark,
    'tone-light bg-white' => ! $dark,
])>
    {{-- Backdrop --}}
    @if ($layout === 'cover' && $image)
        <x-aurora::media :value="$data['image']" size="lg" alt="" loading="eager" class="absolute inset-0 -z-20 size-full object-cover" />
        <div class="absolute inset-0 -z-10 bg-gradient-to-t from-zinc-950 via-zinc-950/70 to-zinc-950/30"></div>
    @elseif ($dark)
        <div class="absolute inset-0 -z-20 bg-aurora"></div>
        <div class="absolute inset-0 -z-10 bg-grid opacity-70"></div>
        <div class="pointer-events-none absolute top-24 left-[12%] -z-10 size-72 rounded-full bg-brand-500/30 blur-3xl animate-float"></div>
        <div class="pointer-events-none absolute top-10 right-[10%] -z-10 size-96 rounded-full bg-accent-400/20 blur-3xl animate-float [animation-delay:-4s]"></div>
    @else
        <div class="absolute inset-0 -z-10 bg-grid"></div>
        <div class="pointer-events-none absolute -top-32 right-0 -z-10 size-[36rem] rounded-full bg-gradient-to-br from-brand-200/70 to-accent-200/50 blur-3xl"></div>
    @endif

    <div @class([
        'mx-auto max-w-7xl px-6 @3xl:px-8',
        'pt-36 pb-24 @3xl:pt-48 @3xl:pb-36' => $layout !== 'split',
        'pt-32 pb-20 @3xl:pt-40 @3xl:pb-28 grid items-center gap-14 @5xl:grid-cols-[1.05fr_1fr]' => $layout === 'split',
    ])>
        <div @class(['mx-auto max-w-4xl text-center' => $layout !== 'split'])>
            @if (shows_field('badge', $data['badge'] ?? null) || shows_field('badge_text', $data['badge_text'] ?? null))
                <div @class(['mb-8 inline-flex items-center gap-3 rounded-full py-1 pr-4 pl-1 text-sm ring-1 backdrop-blur', 'bg-white/5 ring-white/15 text-white/80' => $dark, 'bg-white/80 ring-zinc-200 text-zinc-600 shadow-sm' => ! $dark, 'animate-fade-up' => ! $editing])>
                    <span class="rounded-full bg-brand-600 px-2.5 py-0.5 text-xs font-semibold text-white" @editable('badge')>{{ plain_text($data['badge'] ?? '') }}</span>
                    <span @editable('badge_text')>{{ plain_text($data['badge_text'] ?? '') }}</span>
                </div>
            @endif

            <h1 @class([
                'font-display font-semibold tracking-tight text-balance text-ink whitespace-pre-line',
                'text-5xl/[1.05] @3xl:text-7xl/[1.02] @6xl:text-8xl/[1]' => $layout !== 'split',
                'text-5xl/[1.05] @3xl:text-6xl/[1.05]' => $layout === 'split',
                'animate-fade-up [animation-delay:80ms]' => ! $editing,
            ]) @editable('headline')>{{ inline_text($data['headline'] ?? '') }}</h1>

            @if (shows_field('subheadline', $data['subheadline'] ?? null))
                <p @class(['mt-8 text-lg/8 @3xl:text-xl/8 text-pretty text-ink-muted whitespace-pre-line', 'mx-auto max-w-2xl' => $layout !== 'split', 'max-w-xl' => $layout === 'split', 'animate-fade-up [animation-delay:160ms]' => ! $editing]) @editable('subheadline')>{{ plain_text($data['subheadline'] ?? '') }}</p>
            @endif

            @if ($actions !== [])
                <div @class(['mt-10 flex flex-wrap items-center gap-4', 'justify-center' => $layout !== 'split', 'animate-fade-up [animation-delay:240ms]' => ! $editing])>
                    @isset($actions['primary'])
                        <a href="{{ $actions['primary']['href'] }}" @if ($actions['primary']['target']) target="_blank" rel="noopener" @endif class="group inline-flex items-center gap-2 rounded-full bg-brand-600 px-7 py-4 text-base font-semibold text-white shadow-xl shadow-brand-600/30 transition hover:bg-brand-500 hover:shadow-brand-500/50">
                            <span @editable('primary_label')>{{ plain_text($data['primary_label'] ?? '') }}</span>
                            {{ svg('hero-m-arrow-right', 'size-4 transition group-hover:translate-x-1') }}
                        </a>
                    @endisset
                    @isset($actions['secondary'])
                        <a href="{{ $actions['secondary']['href'] }}" @if ($actions['secondary']['target']) target="_blank" rel="noopener" @endif @class(['inline-flex items-center gap-2 rounded-full px-7 py-4 text-base font-semibold ring-1 ring-inset transition', 'text-white ring-white/20 hover:bg-white/10' => $dark, 'text-zinc-900 ring-zinc-300 hover:bg-zinc-50' => ! $dark])>
                            <span @editable('secondary_label')>{{ plain_text($data['secondary_label'] ?? '') }}</span>
                        </a>
                    @endisset
                </div>
            @endif
        </div>

        @if ($layout === 'split')
            <div class="relative">
                <div class="absolute -inset-4 -z-10 rounded-[2.5rem] bg-gradient-to-tr from-brand-500/20 to-accent-400/20 blur-2xl"></div>
                @if ($image)
                    <x-aurora::media :value="$data['image']" size="lg" sizes="(min-width: 1024px) 50vw, 100vw" loading="eager" class="aspect-[4/3] w-full rounded-[2rem] object-cover shadow-2xl ring-1 ring-zinc-900/10" />
                @else
                    <div class="grid aspect-[4/3] w-full place-items-center rounded-[2rem] bg-zinc-100 text-sm text-zinc-400 ring-1 ring-zinc-900/10 transition hover:text-brand-600 hover:ring-brand-400" {!! \Aurora\Blocks\InlineEditing::pickerAttributes('image') !!}>
                        <span class="flex flex-col items-center gap-2">{{ svg('hero-o-photo', 'size-10 opacity-60') }} Choose an image</span>
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
