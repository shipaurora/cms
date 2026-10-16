<li>
    <div class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5" style="padding-left: {{ 0.5 + $depth * 1.25 }}rem">
        @if ($depth > 0)
            <span class="h-px w-2.5 bg-gray-300 dark:bg-white/20"></span>
        @endif
        <span class="grid size-7 shrink-0 place-items-center rounded-md {{ $node['children'] !== [] ? 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' : 'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' }}">
            <x-filament::icon :icon="$node['icon'] ?? 'phosphor-stack'" class="size-4" />
        </span>
        <span class="font-medium text-gray-950 dark:text-white">{{ $node['label'] }}</span>
        @if ($node['summary'])
            <span class="truncate text-gray-500 dark:text-gray-400">{{ $node['summary'] }}</span>
        @endif
    </div>
    @if ($node['children'] !== [])
        <ul class="space-y-1">
            @foreach ($node['children'] as $child)
                @include('aurora::filament.pages.outline-node', ['node' => $child, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
