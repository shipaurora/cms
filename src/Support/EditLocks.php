<?php

namespace Aurora\Support;

use Aurora\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Who is editing a page right now, so two people don't overwrite each other. Whoever opens
 * it holds the lock while their screen keeps pinging (every PING seconds); a lock nobody
 * has pinged for TTL seconds (a closed tab, a lost connection) is gone by itself, so no
 * page stays locked. Someone else can take it over; saves by others are refused meanwhile.
 */
class EditLocks
{
    /**
     * How often an open editor says it's still there.
     */
    public const PING = 20;

    /**
     * How long a lock lasts without a ping.
     */
    public const TTL = 60;

    /**
     * Who holds it, while it's held.
     *
     * @return array{user_id: string, name: string, avatar: ?string, since: int}|null
     */
    public static function holder(Model $model): ?array
    {
        return Cache::get(static::key($model));
    }

    /**
     * Someone other than the person signed in holds it.
     */
    public static function heldByOther(Model $model, ?User $user = null): bool
    {
        $holder = static::holder($model);
        $user ??= auth()->user();

        return $holder !== null && $holder['user_id'] !== $user?->getKey();
    }

    /**
     * Still here: keep the lock, or take it when nobody holds it. Returns who holds it now.
     *
     * @return array{user_id: string, name: string, avatar: ?string, since: int}|null
     */
    public static function ping(Model $model, User $user): ?array
    {
        $holder = static::holder($model);

        if ($holder !== null && $holder['user_id'] !== $user->getKey()) {
            return $holder;
        }

        return static::take($model, $user, $holder['since'] ?? null);
    }

    /**
     * Hold it, whoever held it before (taking over).
     *
     * @return array{user_id: string, name: string, avatar: ?string, since: int}
     */
    public static function take(Model $model, User $user, ?int $since = null): array
    {
        $holder = ['user_id' => $user->getKey(), 'name' => $user->name, 'avatar' => $user->getFilamentAvatarUrl(), 'since' => $since ?? now()->timestamp];

        Cache::put(static::key($model), $holder, static::TTL);

        return $holder;
    }

    /**
     * Let it go (leaving the page), if it's this person's.
     */
    public static function release(Model $model, User $user): void
    {
        if ((static::holder($model)['user_id'] ?? null) === $user->getKey()) {
            Cache::forget(static::key($model));
        }
    }

    protected static function key(Model $model): string
    {
        return 'aurora.edit-lock.'.$model->getMorphClass().'.'.$model->getKey();
    }
}
