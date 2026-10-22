@props(['data' => []])

@php
    use Aurora\Blocks\InlineEditing;

    $items = array_values(array_filter($data['items'] ?? [], 'is_array'));
    $editing = is_editing();
    $schema = ! $editing && ($data['faq_schema'] ?? true) && $items !== [];
@endphp

<div class="divide-y divide-line rounded-2xl ring-1 ring-line bg-surface" x-data="{ open: {{ ($data['first_open'] ?? true) ? 0 : 'null' }} }">
    @foreach ($items as $index => $item)
        <div>
            <button
                type="button"
                class="flex w-full items-center justify-between gap-6 px-6 py-5 text-left"
                x-on:click="open = open === {{ $index }} ? null : {{ $index }}"
                :aria-expanded="open === {{ $index }}"
            >
                <span class="font-display text-lg font-medium text-ink" {!! InlineEditing::itemAttributes('items', $index, 'question') !!}>{{ plain_text($item['question'] ?? '') }}</span>
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-zinc-900/5 text-ink transition duration-300 [.tone-dark_&]:bg-white/10" :class="open === {{ $index }} && 'rotate-45 bg-brand-600! text-white!'">
                    {{ svg('hero-m-plus', 'size-4') }}
                </span>
            </button>
            <div x-show="open === {{ $index }}" x-collapse @if (! (($data['first_open'] ?? true) && $index === 0)) x-cloak @endif>
                <p class="px-6 pb-6 -mt-1 text-base/7 text-ink-muted whitespace-pre-line" {!! InlineEditing::itemAttributes('items', $index, 'answer') !!}>{{ plain_text($item['answer'] ?? '') }}</p>
            </div>
        </div>
    @endforeach
</div>

@if ($schema)
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn (array $item): array => [
            '@type' => 'Question',
            'name' => (string) ($item['question'] ?? ''),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($item['answer'] ?? '')],
        ], $items),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif
