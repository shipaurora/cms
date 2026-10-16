@php
    /** @var \Aurora\Models\Asset $record */
    $record = $getRecord();
    $tags = $record->tags;
    $shown = $tags->take(6);
    $hidden = $tags->slice(6);
@endphp

@if ($tags->isNotEmpty())
    <div class="cms-asset-tags flex flex-wrap items-center gap-1.5 py-0.5" aria-label="Tags: {{ $tags->pluck('name')->join(', ') }}">
        @foreach ($shown as $tag)
            <span
                wire:key="tag-dot-{{ $record->id }}-{{ $tag->id }}"
                class="size-3 shrink-0 rounded-full ring-1 ring-gray-950/10 dark:ring-white/20"
                style="background-color: {{ $tag->color }}"
                x-tooltip="{ content: @js($tag->name), theme: $store.theme }"
            ></span>
        @endforeach
        @if ($hidden->isNotEmpty())
            <span
                class="text-xs font-medium text-gray-500 dark:text-gray-400"
                x-tooltip="{ content: @js($hidden->pluck('name')->join(', ')), theme: $store.theme }"
            >+{{ $hidden->count() }}</span>
        @endif
    </div>
@endif
