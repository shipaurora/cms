{{-- "Sign in with a passkey" on the login page: only over HTTPS, where browsers offer passkeys
     (Aurora\Support\Passkeys). --}}
@if (\Aurora\Support\Passkeys::available())
    <x-authenticate-passkey redirect="{{ filament()->getCurrentOrDefaultPanel()->getUrl() }}">
        <x-filament::button icon="heroicon-o-key" color="gray" class="w-full">
            {{ __('passkeys::passkeys.authenticate_using_passkey') }}
        </x-filament::button>
    </x-authenticate-passkey>
@endif
