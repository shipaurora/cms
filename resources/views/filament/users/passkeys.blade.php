{{-- Passkey management on your own profile. The passkeys plugin targets Filament's built-in
     profile page, which this panel replaces with the user's own record. --}}
<x-filament::section icon="phosphor-fingerprint" heading="Passkeys" description="Sign in without a password, using your fingerprint, face, screen lock or a security key.">
    <livewire:manage-passkeys />
</x-filament::section>
