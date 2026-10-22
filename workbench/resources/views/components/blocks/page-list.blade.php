@props(['data' => []])

@php
    use Aurora\Models\Page;
    use Aurora\Support\AssetLibrary;

    $editing = is_editing();
    $query = Page::query()->where('is_homepage', false);

    if (! $editing) {
        $query->live();
    }

    if (($data['source'] ?? 'latest') === 'selected') {
        $ids = array_values(array_filter((array) ($data['page_ids'] ?? []), 'is_string'));
        $pages = $query->whereIn('id', $ids)->get()->sortBy(fn (Page $page) => array_search($page->id, $ids))->values();
    } else {
        $pages = $query->latest('published_at')->limit(max(1, min(6, (int) ($data['limit'] ?? 3))))->get();
    }
@endphp

<div class="@container">
    <div class="grid gap-6 @3xl:grid-cols-2 @5xl:grid-cols-3">
        @forelse ($pages as $listed)
            <a href="{{ $editing ? '#' : $listed->url() }}" class="tone-light group flex flex-col overflow-hidden rounded-3xl bg-white ring-1 ring-zinc-900/5 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-zinc-900/10">
                <div class="aspect-[16/9] overflow-hidden bg-gradient-to-br from-brand-100 via-white to-accent-100">
                    @if ($listed->og_image)
                        <img src="{{ AssetLibrary::url($listed->og_image, 'md') }}" alt="" class="size-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                    @else
                        <div class="grid size-full place-items-center font-display text-4xl font-semibold text-brand-300">{{ mb_substr($listed->title, 0, 1) }}</div>
                    @endif
                </div>
                <div class="flex flex-1 flex-col p-6">
                    <p class="text-xs font-medium text-zinc-500">{{ $listed->published_at?->translatedFormat('j F Y') ?? __('Draft') }}</p>
                    <h3 class="mt-2 font-display text-xl font-semibold text-zinc-950">{{ $listed->title }}</h3>
                    @if ($listed->excerpt)
                        <p class="mt-2 line-clamp-3 flex-1 text-sm/6 text-zinc-600">{{ $listed->excerpt }}</p>
                    @endif
                    <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600">
                        <span>{{ $data['link_label'] ?? __('Read more') }}</span>
                        {{ svg('hero-m-arrow-right', 'size-4 transition group-hover:translate-x-1') }}
                    </span>
                </div>
            </a>
        @empty
            @if ($editing)
                <div class="col-span-full rounded-3xl border-2 border-dashed border-line p-10 text-center text-sm text-ink-soft">{{ __('No pages to show yet.') }}</div>
            @endif
        @endforelse
    </div>
</div>
