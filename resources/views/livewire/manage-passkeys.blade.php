@php
    /** @var \Illuminate\Support\Collection<int, \Aurora\Models\Passkey> $passkeys */
@endphp

<div
    x-data="{
        get supported() {
            return typeof window.PublicKeyCredential === 'function'
        },
        adding: false,
        busy: false,
        name: @js($suggestedName),
        options: null,

        async open() {
            this.adding = true
            this.options = null
            this.$nextTick(() => this.$refs.name?.select())
            this.options = await $wire.registrationOptions()
        },

        cancel() {
            this.adding = false
            this.busy = false
        },

        create() {
            if (! this.options || this.busy || ! this.name.trim()) {
                return
            }

            this.busy = true

            // Called straight from the click so the browser shows its passkey prompt.
            window.startRegistration({ optionsJSON: this.options })
                .then((passkey) => $wire.createPasskey(this.name, JSON.stringify(passkey)))
                .then(() => {
                    this.adding = false
                    this.name = @js($suggestedName)
                })
                .catch((error) => {
                    $wire.passkeyFailed(window.describePasskeyError(error))
                    // A challenge is single use; fetch a fresh one for another try.
                    this.options = null
                    $wire.registrationOptions().then((options) => (this.options = options))
                })
                .finally(() => (this.busy = false))
        },
    }"
    class="space-y-4"
>
    @if ($passkeys->isNotEmpty())
        <ul class="divide-y divide-gray-950/5 overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:divide-white/10 dark:ring-white/10">
            @foreach ($passkeys as $passkey)
                <li wire:key="passkey-{{ $passkey->id }}" class="flex items-center gap-3 px-4 py-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon icon="phosphor-fingerprint" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $passkey->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Added {{ $passkey->created_at?->format('M j, Y') }}
                            · {{ $passkey->last_used_at ? 'Last used '.$passkey->last_used_at->diffForHumans() : 'Not used yet' }}
                        </p>
                    </div>
                    {{ ($this->revokeAction)(['passkey' => $passkey->id]) }}
                </li>
            @endforeach
        </ul>
    @else
        <div x-show="! adding" class="flex items-center gap-3 rounded-xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">
            <x-filament::icon icon="phosphor-fingerprint" class="size-5 shrink-0 text-gray-400" />
            {{ __('No passkeys yet. Add one to sign in with Touch ID, Face ID, Windows Hello, your phone or a security key instead of a password.') }}
        </div>
    @endif

    <template x-if="! supported">
        <p class="text-sm text-warning-600 dark:text-warning-400">{{ __('This browser doesn’t support passkeys.') }}</p>
    </template>

    {{-- Not a <form>: this sits inside the profile form, and forms can't nest. --}}
    <div x-show="adding" x-cloak class="space-y-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <div>
            <label for="passkey-name" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Name') }}</label>
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input id="passkey-name" type="text" x-ref="name" x-model="name" maxlength="255" x-on:keydown.enter.prevent="create()" />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('So you can tell your passkeys apart. Your browser then asks where to save it: this device, a password manager, your phone or a security key.') }}</p>
        </div>
        <div class="flex gap-2">
            <x-filament::button type="button" icon="phosphor-fingerprint-bold" x-on:click="create()" x-bind:disabled="! options || busy || ! name.trim()">
                <span x-text="busy ? 'Waiting for your browser…' : (options ? 'Create passkey' : 'Preparing…')">{{ __('Create passkey') }}</span>
            </x-filament::button>
            <x-filament::button type="button" color="gray" x-on:click="cancel()" x-bind:disabled="busy">{{ __('Cancel') }}</x-filament::button>
        </div>
    </div>

    <div x-show="! adding && supported">
        <x-filament::button type="button" color="gray" icon="phosphor-plus-bold" x-on:click="open()">
            {{ __('Add passkey') }}
        </x-filament::button>
    </div>

    <x-filament-actions::modals />
</div>
