/**
 * The browser half of passkeys (WebAuthn): turns the JSON options from spatie/laravel-passkeys
 * into a navigator.credentials call, which is what makes the browser or OS ask to create or
 * use a passkey (Touch ID, Face ID, Windows Hello, a phone or a security key), and turns
 * the result back into JSON for the server.
 *
 * Exposed as window.startRegistration / window.startAuthentication with the same signature
 * as @simplewebauthn/browser, which the package's login script calls.
 */

const toBuffer = (base64url) => {
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64url.length / 4) * 4, '=')

    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0)).buffer
}

const toBase64url = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

const credentialIds = (list = []) => list.map((credential) => ({ ...credential, id: toBuffer(credential.id) }))

function creationOptions(options) {
    if (PublicKeyCredential.parseCreationOptionsFromJSON) {
        return PublicKeyCredential.parseCreationOptionsFromJSON(options)
    }

    return {
        ...options,
        challenge: toBuffer(options.challenge),
        user: { ...options.user, id: toBuffer(options.user.id) },
        excludeCredentials: credentialIds(options.excludeCredentials),
    }
}

function requestOptions(options) {
    if (PublicKeyCredential.parseRequestOptionsFromJSON) {
        return PublicKeyCredential.parseRequestOptionsFromJSON(options)
    }

    return {
        ...options,
        challenge: toBuffer(options.challenge),
        allowCredentials: credentialIds(options.allowCredentials),
    }
}

function toJSON(credential) {
    try {
        if (typeof credential.toJSON === 'function') {
            return credential.toJSON()
        }
    } catch {
        // Some browsers ship toJSON() but throw for certain authenticators; build it by hand.
    }

    const { response } = credential
    const json = {
        id: credential.id,
        rawId: toBase64url(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment ?? undefined,
        clientExtensionResults: credential.getClientExtensionResults(),
        response: { clientDataJSON: toBase64url(response.clientDataJSON) },
    }

    if (response.attestationObject) {
        json.response.attestationObject = toBase64url(response.attestationObject)
        json.response.transports = response.getTransports?.() ?? []
    } else {
        json.response.authenticatorData = toBase64url(response.authenticatorData)
        json.response.signature = toBase64url(response.signature)
        json.response.userHandle = response.userHandle ? toBase64url(response.userHandle) : undefined
    }

    return json
}

/**
 * A message people can act on, for the ways a passkey ceremony usually fails.
 */
export function describePasskeyError(error) {
    switch (error?.name) {
        case 'NotAllowedError':
            return 'The passkey request was cancelled or timed out.'
        case 'InvalidStateError':
            return 'This device already has a passkey for your account.'
        case 'SecurityError':
            return 'Passkeys only work on a secure (https) address that matches this site.'
        case 'NotSupportedError':
            return 'This browser or device can’t create passkeys.'
        default:
            return error?.message || 'Something went wrong with the passkey.'
    }
}

export async function startRegistration({ optionsJSON }) {
    return toJSON(await navigator.credentials.create({ publicKey: creationOptions(optionsJSON) }))
}

export async function startAuthentication({ optionsJSON }) {
    return toJSON(await navigator.credentials.get({ publicKey: requestOptions(optionsJSON) }))
}

window.startRegistration = startRegistration
window.startAuthentication = startAuthentication
window.describePasskeyError = describePasskeyError
window.passkeysSupported = () => typeof window.PublicKeyCredential === 'function'
