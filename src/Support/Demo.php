<?php

namespace Aurora\Support;

/**
 * The demo accounts a website offers: the sign-in page's one-click sign-in when demo mode is
 * on (config('aurora.demo_login')), and the only accounts it signs in as. The website
 * registers its own from a service provider; without any, demo mode has no one to offer.
 *
 *     Demo::register([
 *         ['name' => 'Ada Admin', 'email' => 'ada@example.com', 'role' => 'Super Admin'],
 *     ]);
 */
class Demo
{
    /**
     * @var array<int, array{name: string, email: string, role: string}>
     */
    protected static array $accounts = [];

    /**
     * @param  array<int, array{name: string, email: string, role: string}>  $accounts  all with the password "password"
     */
    public static function register(array $accounts): void
    {
        static::$accounts = array_values($accounts);
    }

    /**
     * @return array<int, array{name: string, email: string, role: string}>
     */
    public static function accounts(): array
    {
        return static::$accounts;
    }

    /**
     * Whether an address is one of the demo accounts (the only ones demo mode signs in as).
     */
    public static function isAccount(string $email): bool
    {
        return in_array($email, array_column(static::$accounts, 'email'), true);
    }

    /**
     * No demo accounts any more (a test, or a site that drops them).
     */
    public static function forget(): void
    {
        static::$accounts = [];
    }
}
