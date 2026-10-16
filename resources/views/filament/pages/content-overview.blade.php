@php
    /** @var \Aurora\Models\Page $page */
    $page = $getRecord();
    // In the language picked with the language switcher: its text, its preview, its editor.
    $locale = \Aurora\Support\ContentLocale::current();
    $blocks = $page->localizedBlocks($locale);
    $outline = \Aurora\Support\BlockOutline::for($blocks);
    $count = \Aurora\Support\BlockOutline::count($blocks);
    $designUrl = \Aurora\Filament\Resources\Pages\PageResource::getUrl('design', ['record' => $page, ...($locale === $page->primaryLocale() ? [] : ['language' => $locale])]);
@endphp

<div class="grid gap-6 lg:grid-cols-[1fr_minmax(0,18rem)]">
    <div class="min-w-0">
        @if ($outline === [])
            <a href="{{ $designUrl }}" class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-gray-200 px-6 py-12 text-center transition hover:border-primary-400 hover:bg-primary-50/50 dark:border-white/10 dark:hover:bg-white/5">
                <x-filament::icon icon="phosphor-paint-brush" class="size-8 text-primary-500" />
                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('This page is a blank canvas') }}</span>
                <span class="text-sm text-gray-500">{{ __('Open the visual editor and drag in your first block.') }}</span>
            </a>
        @else
            <p class="mb-3 text-sm text-gray-500">{{ trans_choice(':count block on this page|:count blocks on this page', $count) }}</p>
            <ul class="max-h-[19.5rem] space-y-1 overflow-y-auto pr-2 [scrollbar-width:thin]">
                @foreach ($outline as $node)
                    @include('aurora::filament.pages.outline-node', ['node' => $node, 'depth' => 0])
                @endforeach
            </ul>
        @endif
    </div>

    <a href="{{ $designUrl }}" wire:key="content-preview-{{ $locale }}" class="group relative block overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:shadow-lg dark:border-white/10" style="height: 22rem" title="{{ __('Open the visual editor') }}">
        <div class="pointer-events-none absolute top-0 left-0 origin-top-left" style="width: 1280px; height: 1760px; transform: scale(0.225)">
            <iframe src="{{ $page->previewUrl(embed: true, locale: $locale) }}" class="size-full border-0" loading="lazy" tabindex="-1" aria-hidden="true"></iframe>
        </div>
        <div class="absolute inset-0 flex items-end justify-center bg-gradient-to-t from-gray-950/70 via-transparent to-transparent p-4 opacity-0 transition group-hover:opacity-100">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-gray-900 shadow">
                <x-filament::icon icon="phosphor-paint-brush-bold" class="size-3.5" /> {{ __('Edit visually') }}
            </span>
        </div>
    </a>
</div>
