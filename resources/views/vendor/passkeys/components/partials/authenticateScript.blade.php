{{-- spatie/laravel-passkeys' sign-in script, with feedback when the passkey prompt is
     cancelled or fails (the original fails silently). startAuthentication() comes from
     resources/js/admin/passkeys.js. --}}
<script>
    async function authenticateWithPasskey(remember = false) {
        try {
            const response = await fetch('{{ route('passkeys.authentication_options') }}')
            const options = await response.json()
            const startAuthenticationResponse = await startAuthentication({ optionsJSON: options })
            const form = document.getElementById('passkey-login-form')

            form.addEventListener('formdata', ({ formData }) => {
                formData.set('remember', remember)
                formData.set('start_authentication_response', JSON.stringify(startAuthenticationResponse))
            })

            form.submit()
        } catch (error) {
            new FilamentNotification()
                .title('Couldn’t sign in with a passkey')
                .body(window.describePasskeyError?.(error) ?? error.message)
                .danger()
                .send()
        }
    }
</script>
