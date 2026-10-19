{{-- Keeps the page's edit lock while it's open (Aurora\Livewire\EditLock), and lets it go on
     leaving: moving elsewhere in the admin, or closing the tab. --}}
<div
    class="cms-edit-lock-keeper"
    wire:poll.{{ \Aurora\Support\EditLocks::PING }}s="ping"
    x-data
    x-init="
        const release = () => $wire.release()
        document.addEventListener('livewire:navigating', release, { once: true })
        window.addEventListener('pagehide', release, { once: true })
    "
>
    @if ($other)
        <div class="cms-edit-lock" role="status">
            @if ($other['avatar'])
                <img src="{{ $other['avatar'] }}" alt="" class="cms-edit-lock-avatar">
            @else
                <x-filament::icon icon="phosphor-lock-simple" class="cms-edit-lock-icon" />
            @endif
            <span class="cms-edit-lock-text">
                <strong>{{ __(':name is editing this page', ['name' => $other['name']]) }}</strong>
                <span>{{ __('Since :when. Your changes can’t be saved while they edit it.', ['when' => \Illuminate\Support\Carbon::createFromTimestamp($other['since'])->diffForHumans()]) }}</span>
            </span>
            <x-filament::button size="sm" color="gray" wire:click="takeOver" wire:confirm="{{ __('Take over editing from :name? Their unsaved changes can’t be saved then.', ['name' => $other['name']]) }}">
                {{ __('Take over') }}
            </x-filament::button>
        </div>
    @endif
</div>
