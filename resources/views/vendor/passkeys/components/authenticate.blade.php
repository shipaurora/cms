{{-- spatie/laravel-passkeys' sign-in button, with the error styled like Filament's. --}}
<div>
    @include('passkeys::components.partials.authenticateScript')

    <form id="passkey-login-form" method="POST" action="{{ route('passkeys.login') }}">
        @csrf
    </form>

    @if ($message = session()->get('authenticatePasskey::message'))
        <p class="mb-3 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-400/20">
            {{ $message }}
        </p>
    @endif

    <div onclick="authenticateWithPasskey()">
        @if ($slot->isEmpty())
            <div class="cursor-pointer underline">
                {{ __('passkeys::passkeys.authenticate_using_passkey') }}
            </div>
        @else
            {{ $slot }}
        @endif
    </div>
</div>
