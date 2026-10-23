@props(['data' => []])

@php
    use Aurora\Support\Markup;

    $items = array_values(array_filter($data['items'] ?? [], 'is_array'));
    $vertical = ($data['layout'] ?? 'horizontal') === 'vertical';
    $columns = match (min(4, count($items))) {
        2 => '@3xl:grid-cols-2',
        3 => '@3xl:grid-cols-3',
        4 => '@3xl:grid-cols-2 @5xl:grid-cols-4',
        default => '',
    };
@endphp

<div class="@container">
    @if ($vertical)
        <ol class="relative ml-4 border-l-2 border-dashed border-line">
            @foreach ($items as $index => $item)
                <li class="relative pb-12 pl-10 last:pb-0">
                    <span class="absolute -left-[0.95rem] top-0 grid size-7 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white ring-4 ring-white [.tone-dark_&]:ring-zinc-950">{{ $index + 1 }}</span>
                    @if (filled($item['kicker'] ?? null) || is_editing())
                        <p class="text-sm font-semibold text-brand-600 [.tone-dark_&]:text-brand-300" @editableItem('items', $index, 'kicker')>{{ $item['kicker'] ?? '' }}</p>
                    @endif
                    <h3 class="mt-1 font-display text-xl font-semibold text-ink" @editableItem('items', $index, 'title')>{{ $item['title'] ?? '' }}</h3>
                    <p class="mt-2 max-w-2xl text-base/7 text-ink-muted whitespace-pre-line" @editableItem('items', $index, 'body')>{{ $item['body'] ?? '' }}</p>
                </li>
            @endforeach
        </ol>
    @else
        <ol class="grid gap-10 @3xl:gap-8 {{ $columns }}">
            @foreach ($items as $index => $item)
                <li class="relative">
                    <div class="flex items-center gap-4">
                        <span class="font-display text-5xl font-semibold text-gradient">{{ filled($item['kicker'] ?? null) ? $item['kicker'] : str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        @unless ($loop->last)
                            <span class="hidden h-px flex-1 bg-gradient-to-r from-brand-300 to-transparent @3xl:block"></span>
                        @endunless
                    </div>
                    <h3 class="mt-4 font-display text-xl font-semibold text-ink" @editableItem('items', $index, 'title')>{{ $item['title'] ?? '' }}</h3>
                    <p class="mt-2 text-base/7 text-ink-muted whitespace-pre-line" @editableItem('items', $index, 'body')>{{ $item['body'] ?? '' }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</div>
