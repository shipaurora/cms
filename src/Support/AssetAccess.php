<?php

namespace Aurora\Support;

use Aurora\Enums\AccessLevel;
use Aurora\Models\Asset;
use Aurora\Models\AssetAccessRule;
use Aurora\Models\AssetFolder;
use Aurora\Models\Role;
use Aurora\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;

/**
 * Who may read, or read and change, the files and folders in the media library.
 *
 * Everything starts open to everyone. A folder or file can have its own access ("custom":
 * a list of rules for everyone, a role or a person); otherwise it follows the folder it's
 * in, up to the top level, which is open to everyone. Super Admins always have full access.
 *
 * This narrows the role permissions, never widens them: reading still needs "Use the media
 * library", changing needs what AssetPolicy asks. It is about the admin: files used on the
 * website keep showing there.
 */
class AssetAccess
{
    public const INHERIT = 'inherit';

    public const CUSTOM = 'custom';

    /**
     * What this person may do with a file or folder (or the top level, null): Read, Write, or
     * null for nothing. Without anyone signed in (the console, seeders, queued work) nothing
     * is narrowed.
     */
    public static function level(?User $user, Asset|AssetFolder|null $item): ?AccessLevel
    {
        if ($user === null || $user->isSuperAdmin() || $item === null) {
            return AccessLevel::Write;
        }

        if ($item instanceof AssetFolder) {
            return array_key_exists($item->getKey(), $levels = static::folderLevels($user)) ? $levels[$item->getKey()] : AccessLevel::Write;
        }

        if ($item->access === self::CUSTOM) {
            return static::levelFrom($item->accessRules, $user);
        }

        return static::level($user, $item->folder_id === null ? null : static::folder($item->folder_id));
    }

    public static function canRead(?User $user, Asset|AssetFolder|null $item): bool
    {
        return static::level($user, $item) !== null;
    }

    public static function canWrite(?User $user, Asset|AssetFolder|null $item): bool
    {
        return static::level($user, $item) === AccessLevel::Write;
    }

    /**
     * Only the files this person may read.
     */
    public static function scopeReadable(Builder $query, ?User $user): void
    {
        if ($user === null || $user->isSuperAdmin()) {
            return;
        }

        $closed = array_keys(array_filter(static::folderLevels($user), fn (?AccessLevel $level): bool => $level === null));
        $ownRules = AssetAccessRule::query()
            ->where('accessible_type', (new Asset)->getMorphClass())
            ->get()
            ->groupBy('accessible_id')
            ->filter(fn (Collection $rules): bool => static::levelFrom($rules, $user) !== null)
            ->keys()
            ->all();
        $table = $query->getModel()->getTable();

        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query
                ->where("{$table}.access", '!=', self::CUSTOM)
                ->where(fn (Builder $query) => $query->whereNull("{$table}.folder_id")->orWhereNotIn("{$table}.folder_id", $closed)))
            ->orWhere(fn (Builder $query) => $query->where("{$table}.access", self::CUSTOM)->whereIn("{$table}.id", $ownRules)));
    }

    /**
     * The folders this person may see, by id.
     *
     * @return array<int, string>
     */
    public static function readableFolderIds(User $user): array
    {
        return array_keys(array_filter(static::folderLevels($user), fn (?AccessLevel $level): bool => $level !== null));
    }

    /**
     * Give a file or folder its own access, or let it follow its folder again.
     *
     * @param  array<int, array{grantee: string, level: string}>  $rules  grantee is "everyone", "role:{id}" or "user:{id}"
     */
    public static function put(Asset|AssetFolder $item, string $mode, array $rules = []): void
    {
        DB::transaction(function () use ($item, $mode, $rules): void {
            $item->forceFill(['access' => $mode])->save();
            $item->accessRules()->delete();

            if ($mode !== self::CUSTOM) {
                return;
            }

            foreach ($rules as $rule) {
                [$type, $id] = array_pad(explode(':', $rule['grantee'], 2), 2, null);

                $item->accessRules()->create([
                    'grantee_type' => match ($type) {
                        'role' => (new Role)->getMorphClass(),
                        'user' => (new User)->getMorphClass(),
                        default => null,
                    },
                    'grantee_id' => in_array($type, ['role', 'user'], true) ? $id : null,
                    'level' => $rule['level'],
                ]);
            }
        });

        $item->unsetRelation('accessRules');
        Once::flush();
    }

    /**
     * A file's or folder's rules as the Access dialog lists them.
     *
     * @return array<int, array{grantee: string, level: string}>
     */
    public static function rules(Asset|AssetFolder $item): array
    {
        return $item->accessRules->map(fn (AssetAccessRule $rule): array => [
            'grantee' => match (true) {
                $rule->grantee_type === null => 'everyone',
                $rule->grantee_type === (new Role)->getMorphClass() => 'role:'.$rule->grantee_id,
                default => 'user:'.$rule->grantee_id,
            },
            'level' => $rule->level->value,
        ])->all();
    }

    /**
     * Who the rules can name: everyone, each role and each person (as their name, or with
     * $badges as HTML with their picture, for the Access dialog's select).
     *
     * @return array<string, array<string, string>>
     */
    public static function grantees(bool $badges = false): array
    {
        // Roles and everyone with an icon, as people have their picture: Super Admin with the red
        // padlock the Roles list gives it.
        $icon = fn (string $icon, string $label, bool $danger = false): string => sprintf(
            '<span class="cms-grantee-option%s">%s<span>%s</span></span>',
            $danger ? ' cms-grantee-option-danger' : '',
            svg($icon, 'cms-grantee-option-icon')->toHtml(),
            e($label),
        );

        return [
            __('Everyone') => ['everyone' => $badges ? $icon('phosphor-globe-hemisphere-west', __('Everyone')) : __('Everyone')],
            __('Roles') => Role::query()->get()->sortBy(fn (Role $role): string => $role->displayName())->mapWithKeys(fn (Role $role): array => ['role:'.$role->getKey() => $badges
                ? ($role->name === User::SUPER_ADMIN ? $icon('phosphor-lock-bold', $role->displayName(), danger: true) : $icon('phosphor-users-three', $role->displayName()))
                : $role->displayName()])->all(),
            __('People') => User::query()->orderBy('name')->get()->mapWithKeys(fn (User $user): array => ['user:'.$user->getKey() => $badges ? PeoplePicker::option($user) : $user->name])->all(),
        ];
    }

    /**
     * Whether a person keeps write access under a set of rules (as the Access dialog sends
     * them): nobody locks themselves out of a folder.
     *
     * @param  array<int, array{grantee: string, level: string}>  $rules
     */
    public static function keepsWrite(User $user, array $rules): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $roles = array_map(fn (string $id): string => 'role:'.$id, $user->roles->modelKeys());

        return collect($rules)->contains(fn (array $rule): bool => ($rule['level'] ?? null) === AccessLevel::Write->value
            && in_array($rule['grantee'] ?? null, ['everyone', 'user:'.$user->getKey(), ...$roles], true));
    }

    /**
     * Who may do what, in a sentence: "Everyone can read and write", "Editors can read and
     * write; Authors can read".
     *
     * @param  array<int, array{grantee: string, level: string}>|null  $rules  null: everyone
     */
    public static function describe(?array $rules): string
    {
        if ($rules === null) {
            return __('Everyone can read and write.');
        }

        if ($rules === []) {
            return __('Only Super Admins.');
        }

        $names = collect(static::grantees())->collapse();

        return collect($rules)
            ->map(fn (array $rule): string => __(':who: :level', ['who' => $names[$rule['grantee']] ?? __('Someone removed'), 'level' => AccessLevel::from($rule['level'])->getLabel()]))
            ->implode(' · ');
    }

    /**
     * The folder whose access applies to an item that follows its folder: the nearest one up
     * with its own, or null for the top level (everyone).
     */
    public static function source(Asset|AssetFolder $item): ?AssetFolder
    {
        $folderId = $item instanceof Asset ? $item->folder_id : $item->parent_id;

        for ($folder = $folderId ? static::folder($folderId) : null; $folder !== null; $folder = $folder->parent_id ? static::folder($folder->parent_id) : null) {
            if ($folder->access === self::CUSTOM) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Each folder's access for one person, worked out in one pass and kept for the request.
     *
     * @return array<string, ?AccessLevel>
     */
    protected static function folderLevels(User $user): array
    {
        return once(function () use ($user): array {
            $folders = static::folders();
            $rules = AssetAccessRule::query()->where('accessible_type', (new AssetFolder)->getMorphClass())->get()->groupBy('accessible_id');
            $levels = [];

            $resolve = function (?string $id) use (&$resolve, &$levels, $folders, $rules, $user): ?AccessLevel {
                if ($id === null || ! isset($folders[$id])) {
                    return AccessLevel::Write;
                }

                if (array_key_exists($id, $levels)) {
                    return $levels[$id];
                }

                $folder = $folders[$id];

                return $levels[$id] = $folder->access === self::CUSTOM
                    ? static::levelFrom($rules[$id] ?? collect(), $user)
                    : $resolve($folder->parent_id);
            };

            foreach ($folders->keys() as $id) {
                $resolve($id);
            }

            return $levels;
        });
    }

    /**
     * @return Collection<string, AssetFolder>
     */
    protected static function folders(): Collection
    {
        return once(fn (): Collection => AssetFolder::query()->get(['id', 'parent_id', 'access', 'name'])->keyBy('id'));
    }

    protected static function folder(string $id): ?AssetFolder
    {
        return static::folders()->get($id);
    }

    /**
     * The most a person gets from a list of rules: from those for everyone, them, or a role
     * they have.
     *
     * @param  Collection<int, AssetAccessRule>  $rules
     */
    protected static function levelFrom(Collection $rules, User $user): ?AccessLevel
    {
        $roles = $user->roles->modelKeys();
        $level = null;

        foreach ($rules as $rule) {
            $applies = match (true) {
                $rule->grantee_type === null => true,
                $rule->grantee_type === (new Role)->getMorphClass() => in_array($rule->grantee_id, $roles, true),
                default => $rule->grantee_id === $user->getKey(),
            };

            if ($applies && ($level === null || $rule->level === AccessLevel::Write)) {
                $level = $rule->level;
            }
        }

        return $level;
    }
}
