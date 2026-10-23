@props(['data' => []])

@php
    $items = array_values(array_filter($data['items'] ?? [], 'is_array'));
    $pills = ($data['style'] ?? 'pills') === 'pills';
    // Ties each tab to its panel; unique per block on the page.
    $uid = 'tabs-'.\Illuminate\Support\Str::random(6);
@endphp

<div
    x-data="{
        tab: 0,
        count: {{ count($items) }},
        // Arrow keys, Home and End move between tabs, and focus goes along.
        go(index) {
            this.tab = (index + this.count) % this.count
            this.$nextTick(() => this.$refs['tab' + this.tab]?.focus())
        },
    }"
    class="@container"
>
    <div
        role="tablist"
        x-on:keydown.right.prevent="go(tab + 1)"
        x-on:keydown.left.prevent="go(tab - 1)"
        x-on:keydown.home.prevent="go(0)"
        x-on:keydown.end.prevent="go(count - 1)"
        @class([
        'flex flex-wrap gap-1',
        'inline-flex rounded-full bg-zinc-900/5 p-1 [.tone-dark_&]:bg-white/10' => $pills,
        'border-b border-line gap-6' => ! $pills,
    ])>
        @foreach ($items as $index => $item)
            <button
                type="button"
                role="tab"
                id="{{ $uid }}-tab-{{ $index }}"
                aria-controls="{{ $uid }}-panel-{{ $index }}"
                x-ref="tab{{ $index }}"
                x-on:click="tab = {{ $index }}"
                :aria-selected="tab === {{ $index }}"
                :tabindex="tab === {{ $index }} ? 0 : -1"
                @class([
                    'text-sm font-semibold transition',
                    'rounded-full px-4 py-2 text-ink-muted hover:text-ink aria-selected:bg-white aria-selected:text-zinc-900 aria-selected:shadow-sm' => $pills,
                    '-mb-px border-b-2 border-transparent pb-3 text-ink-muted hover:text-ink aria-selected:border-brand-500 aria-selected:text-ink' => ! $pills,
                ])
            >{{ $item['label'] ?? __('Tab') }}</button>
        @endforeach
    </div>

    {{-- The panels share one grid cell, so the block is as tall as its tallest panel and
         nothing below it moves when the tab changes: the old panel goes, the new one fades in. --}}
    <div class="mt-8 grid">
        @foreach ($items as $index => $item)
            <div
                role="tabpanel"
                id="{{ $uid }}-panel-{{ $index }}"
                aria-labelledby="{{ $uid }}-tab-{{ $index }}"
                tabindex="0"
                @class(['col-start-1 row-start-1 max-w-3xl transition-opacity duration-300', 'invisible opacity-0' => $index > 0])
                :class="{ 'invisible opacity-0': tab !== {{ $index }} }"
            >
                <h3 class="font-display text-2xl font-semibold text-ink" @editableItem('items', $index, 'title')>{{ plain_text($item['title'] ?? '') }}</h3>
                <p class="mt-3 text-base/7 text-ink-muted whitespace-pre-line" @editableItem('items', $index, 'body')>{{ plain_text($item['body'] ?? '') }}</p>
            </div>
        @endforeach
    </div>
</div>
