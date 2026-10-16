<?php

namespace Aurora\Models;

use Aurora\Support\AssetAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * A folder in the media library. Folders nest; images belong to a folder by id, so a
 * folder can be renamed or moved without touching the images in it.
 */
#[Fillable(['name', 'parent_id', 'access'])]
class AssetFolder extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        // Deleting a folder never deletes images: its contents move up one level.
        static::deleting(function (AssetFolder $folder): void {
            $folder->children()->update(['parent_id' => $folder->parent_id]);
            $folder->assets()->update(['folder_id' => $folder->parent_id]);
            $folder->accessRules()->delete();
        });
    }

    /**
     * Whether a place (a folder, or the top level) already has a folder of that name: names are
     * unique within a place.
     */
    public static function nameTakenIn(?string $parentId, string $name, ?string $except = null): bool
    {
        return static::query()->where('parent_id', $parentId)->where('name', $name)->when($except, fn ($query) => $query->whereKeyNot($except))->exists();
    }

    /**
     * Names of subfolders that couldn't move up a level on deleting this folder: their new
     * place already has a folder of that name.
     *
     * @return array<int, string>
     */
    public function clashesOnDelete(): array
    {
        return $this->children()->pluck('name')->filter(fn (string $name): bool => static::nameTakenIn($this->parent_id, $name, $this->getKey()))->values()->all();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Its own access, when it has some ("custom"); otherwise it follows its folder.
     */
    public function accessRules(): MorphMany
    {
        return $this->morphMany(AssetAccessRule::class, 'accessible');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'folder_id');
    }

    /**
     * This folder and the folders above it, outermost first.
     *
     * @return Collection<int, AssetFolder>
     */
    public function trail(): Collection
    {
        $trail = collect([$this]);
        $folder = $this;

        while ($folder->parent_id && ($folder = $folder->parent)) {
            $trail->prepend($folder);
        }

        return $trail;
    }

    /**
     * "Photos / Norway": from every folder's path, worked out once per request (a table shows
     * many), rather than walking up one query per level.
     */
    public function path(string $separator = ' / '): string
    {
        $path = once(fn (): array => static::paths())[$this->getKey()] ?? null;

        return $path === null ? $this->name : implode($separator, [...$path['parents'], $path['name']]);
    }

    /**
     * How many files each folder holds, its subfolders' included: two queries for all of them.
     *
     * @return array<string, int> folder id => files
     */
    public static function assetTotals(): array
    {
        return once(function (): array {
            $parents = static::query()->pluck('parent_id', 'id')->all();
            $own = Asset::query()->tap(fn ($query) => AssetAccess::scopeReadable($query, auth()->user()))->whereNotNull('folder_id')->toBase()->selectRaw('folder_id, count(*) as total')->groupBy('folder_id')->pluck('total', 'folder_id')->all();
            $totals = array_fill_keys(array_keys($parents), 0);

            // Each folder's files count for it and every folder above it.
            foreach ($own as $folderId => $count) {
                for ($id = $folderId; $id !== null && array_key_exists($id, $parents); $id = $parents[$id]) {
                    $totals[$id] += (int) $count;
                }
            }

            return $totals;
        });
    }

    /**
     * Ids of this folder and every folder inside it.
     *
     * @return array<int, string>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        while ($level !== []) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->all();
            array_push($ids, ...$level);
        }

        return $ids;
    }

    /**
     * Every folder in tree order with its depth and the names of the folders above it,
     * leaving out $except (a folder and its subfolders, when choosing where to move it).
     *
     * @param  array<int, string>  $except
     * @return array<string, array{name: string, depth: int, parents: array<int, string>}>
     */
    public static function paths(array $except = []): array
    {
        $folders = self::query()->orderBy('name')->get()->groupBy('parent_id');
        $paths = [];

        // Folders this person can't read aren't offered (nor what's in them).
        if (($user = auth()->user()) !== null && ! $user->isSuperAdmin()) {
            $readable = AssetAccess::readableFolderIds($user);
            $except = [...$except, ...$folders->flatten()->pluck('id')->reject(fn (string $id): bool => in_array($id, $readable, true))->all()];
        }

        $walk = function (?string $parent, array $parents) use (&$walk, &$paths, $folders, $except): void {
            foreach ($folders->get($parent ?? '', collect()) as $folder) {
                if (in_array($folder->id, $except, true)) {
                    continue;
                }

                $paths[$folder->id] = ['name' => $folder->name, 'depth' => count($parents), 'parents' => $parents];
                $walk($folder->id, [...$parents, $folder->name]);
            }
        };

        $walk(null, []);

        return $paths;
    }

    /**
     * Folders as "Parent / Child" labels, in tree order.
     *
     * @param  array<int, string>  $except
     * @return array<string, string>
     */
    public static function options(array $except = []): array
    {
        return array_map(
            fn (array $path): string => implode(' / ', [...$path['parents'], $path['name']]),
            static::paths($except),
        );
    }

    /**
     * The folder at a path like "Photos/Norway", created (with its parents) when missing.
     */
    public static function findOrCreatePath(?string $path): ?self
    {
        $names = array_values(array_filter(array_map('trim', preg_split('#[/>]#', (string) $path))));
        $folder = null;

        foreach ($names as $name) {
            $folder = self::query()->firstOrCreate(['parent_id' => $folder?->id, 'name' => $name]);
        }

        return $folder;
    }
}
