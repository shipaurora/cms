<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Illuminate\Support\Facades\Hash;

/**
 * Password-protected pages: visitors type the page's password once, and it stays open to
 * them for the rest of their visit (the session). Changing the password locks it again.
 * Being signed in to the admin doesn't open it: the password is asked of everyone.
 */
class PagePassword
{
    protected const SESSION_KEY = 'aurora.unlocked_pages';

    /**
     * Whether the visitor sees the password form instead of the page.
     */
    public static function isLocked(Page $page): bool
    {
        return $page->isPasswordProtected()
            && ! hash_equals(static::fingerprint($page), (string) session(self::SESSION_KEY.'.'.$page->getKey()));
    }

    /**
     * Open the page for this visit when the password is right.
     */
    public static function unlock(Page $page, string $password): bool
    {
        if (! $page->isPasswordProtected() || ! Hash::check($password, $page->password)) {
            return false;
        }

        session()->put(self::SESSION_KEY.'.'.$page->getKey(), static::fingerprint($page));

        return true;
    }

    /**
     * Ties an unlock to the password it was made with, so a new password locks the page again.
     */
    protected static function fingerprint(Page $page): string
    {
        return hash_hmac('sha256', (string) $page->password, (string) config('app.key'));
    }
}
