<?php

namespace Aurora\Models;

use Aurora\Aurora;
use Aurora\Models\Pivots\RoleAssignment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Translatable\HasTranslations;

/**
 * A role. Its "name" is the fixed key permission checks use (hasRole('Editor'), the Super
 * Admin bypass); people see its label and description, in the language of their admin panel.
 */
class Role extends SpatieRole
{
    use HasTranslations;
    use HasUuids;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['label', 'description'];

    /**
     * The Super Admin role is what Gate::before checks by name: renaming or deleting it
     * would silently take every Super Admin's access away.
     */
    protected static function booted(): void
    {
        static::updating(function (self $role): void {
            if ($role->getOriginal('name') === User::SUPER_ADMIN && $role->isDirty('name')) {
                throw ValidationException::withMessages(['name' => 'The Super Admin role cannot be renamed.']);
            }
        });

        static::deleting(function (self $role): void {
            if ($role->name === User::SUPER_ADMIN) {
                throw ValidationException::withMessages(['name' => 'The Super Admin role cannot be deleted.']);
            }
        });
    }

    /**
     * The role's name in the admin's language. Without one in that language: the name, through
     * the translation files (so the built-in roles read "Redaktør" in Norwegian).
     */
    public function displayName(): string
    {
        return $this->getTranslation('label', app()->getLocale(), false) ?: __($this->name);
    }

    /**
     * What people with this role do, in the admin's language, else the default language's
     * text through the translation files.
     */
    public function displayDescription(): ?string
    {
        $description = $this->getTranslation('description', app()->getLocale(), false)
            ?: $this->getTranslation('description', Locale::defaultCode(), false);

        return filled($description) ? __($description) : null;
    }

    /**
     * The languages the admin panel is translated into (lang/*.json): the ones a role is named in.
     *
     * @return array<int, string>
     */
    public static function adminLocales(): array
    {
        return collect(Aurora::langPaths())
            ->flatMap(fn (string $lang): array => glob($lang.'/*.json') ?: [])
            ->map(fn (string $file): string => basename($file, '.json'))
            ->unique()->sort()->values()->all();
    }

    /**
     * Roles are written in the admin's default language first.
     */
    public function primaryLocale(): string
    {
        return Locale::defaultCode();
    }

    public function users(): BelongsToMany
    {
        return parent::users()->using(RoleAssignment::class);
    }
}
