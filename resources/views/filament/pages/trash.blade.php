<x-filament-panels::page>
    @php($counts = array_filter($this->counts()))

    {{-- One kind of content at a time: each has its own columns and permissions. Only the
         kinds with something in the trash get a tab. --}}
    @if (count($counts) > 1)
        <x-filament::tabs class="self-start">
            @foreach (array_intersect_key(static::kinds(), $counts) as $key => $kind)
                <x-filament::tabs.item
                    :active="$this->kind === $key"
                    :icon="$kind['icon']"
                    :badge="$counts[$key]"
                    badge-color="gray"
                    wire:click="$set('kind', '{{ $key }}')"
                >
                    {{ $kind['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
