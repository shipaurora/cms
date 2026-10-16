{{--
    CMS override of Filament's database notifications: a dropdown under the bell, like the
    user menu, instead of a slide-over. Opening it marks everything as read. The Livewire
    component (loading, polling, actions) is Filament's own.
--}}

@php
    use Illuminate\Contracts\Pagination\Paginator;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
@endphp

<div
    class="fi-no-database cms-notifications"
    @if ($pollingInterval) wire:poll.{{ $pollingInterval }} @endif
>
    <x-filament::dropdown placement="bottom-end" width="sm" teleport class="cms-notifications-dropdown" wire:key="database-notifications.dropdown">
        <x-slot name="trigger">
            @if ($unreadNotificationsCount)
                {{-- Opening the list reads it: the bell's count clears. --}}
                <span hidden x-effect="isOpen && $wire.markAllNotificationsAsRead()"></span>
            @endif

            @if ($trigger = $this->getTrigger())
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            @endif
        </x-slot>

        <div class="cms-notifications-header">
            <h2 id="database-notifications.heading" class="cms-notifications-heading">
                {{ __('filament-notifications::database.modal.heading') }}
            </h2>

            @if ($hasNotifications && $this->clearNotificationsAction?->isVisible())
                {{ $this->clearNotificationsAction }}
            @endif
        </div>

        @if ($hasNotifications)
            <div
                aria-labelledby="database-notifications.heading"
                role="list"
                class="fi-no-notifications cms-notifications-list"
            >
                @foreach ($notifications as $notification)
                    <div
                        role="listitem"
                        wire:key="{{ $notification->getKey() }}.database-notifications.ctn"
                        @class([
                            'fi-no-notification-read-ctn' => ! $notification->unread(),
                            'fi-no-notification-unread-ctn' => $notification->unread(),
                        ])
                    >
                        @if ($notification->unread())
                            <span class="fi-sr-only">
                                {{ __('filament-notifications::database.modal.unread_label') }}
                            </span>
                        @endif

                        {{ $this->getNotification($notification)->inline() }}
                    </div>
                @endforeach
            </div>

            @if ($isPaginated)
                <div class="cms-notifications-footer">
                    <x-filament::pagination :paginator="$notifications" />
                </div>
            @endif
        @else
            <div class="cms-notifications-empty">
                {{ svg('phosphor-bell-slash', 'size-6') }}
                <p class="cms-notifications-empty-heading">{{ __('filament-notifications::database.modal.empty.heading') }}</p>
                <p>{{ __('filament-notifications::database.modal.empty.description') }}</p>
            </div>
        @endif
    </x-filament::dropdown>

    @if ($broadcastChannel = $this->getBroadcastChannel())
        @script
            <script>
                setUpFilamentBroadcastNotifications({
                    $wire,
                    broadcastChannel: @js($broadcastChannel),
                    event: '.database-notifications.sent',
                    onNotification: () => $wire.call('$refresh'),
                })
            </script>
        @endscript
    @endif
</div>
