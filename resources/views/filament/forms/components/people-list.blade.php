@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($people->isEmpty())
        <div class="rounded-xl border-2 border-dashed border-gray-200 p-8 text-center text-sm text-gray-500 dark:border-white/10">
            {{ __('Nobody found.') }}
        </div>
    @else
        <ul class="cms-people-list -mx-1 max-h-[26rem] space-y-1 overflow-y-auto p-1">
            @foreach ($people as $person)
                <li wire:key="person-{{ $person->id }}">
                    <label class="cms-people-row group flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition">
                        <input
                            type="{{ $multiple ? 'checkbox' : 'radio' }}"
                            value="{{ $person->id }}"
                            wire:model.live="{{ $statePath }}"
                            @unless ($multiple)
                                {{-- One person: choosing them is the whole job. --}}
                                x-on:change="$wire.callMountedAction()"
                            @endunless
                            class="peer sr-only"
                        >
                        <img src="{{ filament()->getUserAvatarUrl($person) }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-full object-cover ring-1 ring-gray-950/10 dark:ring-white/10">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $person->name }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ collect([$person->job_title, $person->email])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="hidden shrink-0 gap-1 sm:flex">
                            @foreach ($person->roles as $role)
                                <x-filament::badge size="sm" color="gray">{{ $role->displayName() }}</x-filament::badge>
                            @endforeach
                        </span>
                        <span class="cms-people-check grid size-5 shrink-0 place-items-center rounded-full text-white ring-1 ring-gray-950/15 transition dark:ring-white/20">
                            <x-filament::icon icon="phosphor-check-bold" class="size-3" />
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif
</x-dynamic-component>
