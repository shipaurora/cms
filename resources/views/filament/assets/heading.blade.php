{{-- The open folder's heading (ListAssets::getHeading()): a back arrow to the folder above, then its name. --}}
<span class="inline-flex items-center gap-2">
    <x-filament::icon-button
        icon="phosphor-arrow-left-bold"
        color="gray"
        :label="__('Up to :folder', ['folder' => $parent?->name ?? __('Library')])"
        :tooltip="__('Up to :folder', ['folder' => $parent?->name ?? __('Library')])"
        wire:click="openFolder({{ \Illuminate\Support\Js::from($parent?->id) }})"
    />
    <span>{{ $folder->name }}</span>
</span>
