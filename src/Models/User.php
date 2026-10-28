<?php

namespace Aurora\Models;

use Aurora\Database\Factories\UserFactory;
use Aurora\Models\Pivots\RoleAssignment;
use EslamRedaDiv\FilamentCopilot\Concerns\HasCopilotChat;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use SensitiveParameter;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'locale', 'theme', 'admin_theme', 'password', 'avatar', 'job_title', 'is_active'])]
#[Hidden(['password', 'remember_token', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasPasskeys, HasTenants, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasCopilotChat, HasFactory, HasUuids, InteractsWithPasskeys, Notifiable;

    use HasRoles {
        roles as assignedRoles;
    }

    public const SUPER_ADMIN = 'Super Admin';

    /**
     * Roles and permissions belong to the web guard, also when the user arrives through
     * the "api" guard (an MCP client with a Passport token).
     */
    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_signed_in_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'sidebar_collapsed' => 'boolean',
            'dashboard' => 'array',
            'passkey_prompt_dismissed_at' => 'datetime',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        // Nobody can lock themselves out, whichever screen or bulk action they use.
        static::updating(function (self $user): void {
            if ($user->is(auth()->user()) && $user->isDirty('is_active') && $user->is_active === false) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
            }
        });

        static::deleting(function (self $user): void {
            if ($user->is(auth()->user())) {
                throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
            }
        });
    }

    /**
     * One type for a person in every polymorphic row (their roles, their tokens…): the app's
     * own user model when it extends this one. Otherwise someone created through Aurora's
     * model (the installer, make:aurora-user, the admin) would have roles the app's model,
     * the one that signs in, never sees.
     */
    public function getMorphClass(): string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && $model !== static::class && is_subclass_of($model, self::class)
            ? (new $model)->getMorphClass()
            : parent::getMorphClass();
    }

    /**
     * Spatie's relation, through a pivot model that refuses unsafe removals.
     */
    public function roles(): BelongsToMany
    {
        return $this->assignedRoles()->using(RoleAssignment::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active !== false && $this->roles()->exists();
    }

    /**
     * The sites this person was given.
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    public function canManageSites(): bool
    {
        return $this->can('manage-sites');
    }

    /**
     * The sites this person can work on: every site for those who manage sites; else the
     * ones they were given, or the default site when they were given none.
     *
     * @return Collection<int, Site>
     */
    public function accessibleSites(): Collection
    {
        if ($this->canManageSites()) {
            return Site::query()->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get();
        }

        $sites = $this->sites()->orderByDesc('is_default')->orderBy('sort')->orderBy('name')->get();

        return $sites->isNotEmpty() ? $sites : Site::query()->where('is_default', true)->get();
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->accessibleSites();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Site && $this->accessibleSites()->contains($tenant);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }

    /**
     * Impersonation ("view as"): a permission no role has by default, so out of the box
     * only Super Admins (who have every permission) can do it.
     */
    public function canImpersonate(): bool
    {
        return $this->can('impersonate User');
    }

    /**
     * Super Admins can never be impersonated: that would hand out everything.
     */
    public function canBeImpersonated(): bool
    {
        return ! $this->isSuperAdmin() && $this->is_active !== false && ! $this->is(auth()->user());
    }

    /**
     * The person's roles as people read them, in the admin's language (getRoleNames() gives the keys).
     *
     * @return Collection<int, string>
     */
    public function roleLabels(): Collection
    {
        return $this->roles->map(fn (Role $role): string => $role->displayName())->values();
    }

    /**
     * Whether this person may hand out a role: a Super Admin any; anyone else only a role whose
     * permissions they hold themselves, and never Super Admin. Nobody grants more than they have.
     */
    public function canGrantRole(Role $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($role->name === self::SUPER_ADMIN) {
            return false;
        }

        return $role->permissions->pluck('name')->diff($this->getAllPermissions()->pluck('name'))->isEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN);
    }

    /**
     * The authenticator apps this person signs in with as a second step; any of them works.
     */
    public function authenticatorApps(): HasMany
    {
        return $this->hasMany(AuthenticatorApp::class)->oldest()->orderBy('id');
    }

    /**
     * Filament's app authentication knows one secret; here it is the newest app's. Signing in
     * checks every app (Aurora\Filament\Auth\AuthenticatorApps).
     */
    public function getAppAuthenticationSecret(): ?string
    {
        return $this->authenticatorApps()->reorder()->latest()->first()?->secret;
    }

    /**
     * Setting up an app adds one, under the next free name ("Authenticator app 2"); none
     * removes them all.
     */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        if ($secret === null) {
            $this->authenticatorApps()->delete();

            return;
        }

        $count = $this->authenticatorApps()->count();

        $this->authenticatorApps()->create([
            'name' => $count === 0 ? __('Authenticator app') : __('Authenticator app :number', ['number' => $count + 1]),
            'secret' => $secret,
        ]);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'author_id');
    }

    public function mcpActivities(): HasMany
    {
        return $this->hasMany(McpActivity::class);
    }
}
