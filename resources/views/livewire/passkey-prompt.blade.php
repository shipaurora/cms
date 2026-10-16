<div
    x-data="{
        // Passkeys need HTTPS (a secure context) and a browser that can make them.
        supported: window.isSecureContext && typeof window.PublicKeyCredential === 'function',
        options: null,
        busy: false,
        forever: false,
        done: false,

        async init() {
            if (! this.supported) {
                return
            }

            this.$nextTick(() => this.$dispatch('open-modal', { id: 'passkey-prompt' }))

            // Fetched now so the click can start the browser's prompt straight away (Safari).
            this.options = await $wire.registrationOptions()
        },

        create() {
            if (! this.options || this.busy) {
                return
            }

            this.busy = true

            window.startRegistration({ optionsJSON: this.options })
                .then((passkey) => $wire.createPasskey(@js($suggestedName), JSON.stringify(passkey)))
                .then(() => this.close())
                .catch((error) => {
                    $wire.passkeyFailed(window.describePasskeyError(error))
                    // A challenge is single use; fetch a fresh one for another try.
                    this.options = null
                    $wire.registrationOptions().then((options) => (this.options = options))
                })
                .finally(() => (this.busy = false))
        },

        later() {
            $wire.dismiss(this.forever)
            this.close()
        },

        close() {
            this.done = true
            this.$dispatch('close-modal', { id: 'passkey-prompt' })
        },
    }"
    x-on:modal-closed.window="if ($event.detail.id === 'passkey-prompt' && ! done) { done = true; $wire.dismiss(forever) }"
>
    <x-filament::modal id="passkey-prompt" width="md" icon="phosphor-fingerprint" :close-by-clicking-away="false">
        <x-slot name="heading">{{ __('Sign in with a passkey next time?') }}</x-slot>

        <x-slot name="description">{{ __('A passkey lets you sign in with your fingerprint, your face or your device’s PIN instead of a password.') }}</x-slot>

        <ul class="cms-passkey-reasons space-y-3 text-sm">
            @foreach ([
                ['phosphor-lightning', __('Faster'), __('Nothing to type or remember: one touch and you’re in.')],
                ['phosphor-shield-check', __('Safer'), __('It can’t be guessed, leaked or phished, because it only works on this site.')],
                ['phosphor-lock-key', __('Private'), __('Your fingerprint or face never leaves your device.')],
            ] as [$icon, $title, $text])
                <li class="flex gap-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon :icon="$icon" class="size-4" />
                    </span>
                    <span>
                        <span class="block font-semibold text-gray-950 dark:text-white">{{ $title }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $text }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        <label class="mt-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <x-filament::input.checkbox x-model="forever" />
            {{ __('Don’t show this again') }}
        </label>

        <x-slot name="footerActions">
            <x-filament::button icon="phosphor-fingerprint" x-on:click="create()" x-bind:disabled="! options || busy">
                {{ __('Create a passkey') }}
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="later()">
                {{ __('Not now') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
