<?php

namespace Aurora\Support;

/**
 * Whether passkeys can be offered: browsers only make and use them over HTTPS. Without it,
 * signing in with a passkey, adding one and requiring one are all left out.
 */
class Passkeys
{
    public static function available(): bool
    {
        return request()->isSecure();
    }
}
