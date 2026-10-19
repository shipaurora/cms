<?php

namespace Aurora\Console\Commands;

use Aurora\Models\Role;
use Aurora\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('make:aurora-user
    {--name= : The name of the user}
    {--email= : A valid and unique email address}
    {--password= : The password for the user (min. 8 characters)}
    {--role= : The role to give the user, by name (e.g. Editor or "Super Admin")}')]
#[Description('Create a new Aurora user with a role')]
class MakeUser extends Command
{
    public function handle(): int
    {
        $roles = Role::query()->orderBy('name')->get();

        if ($roles->isEmpty()) {
            $this->components->error('There are no roles yet. Set up the CMS first: php artisan aurora:install');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive() && collect(['name', 'email', 'password', 'role'])->contains(fn (string $option): bool => blank($this->option($option)))) {
            $this->components->error('Pass --name, --email, --password and --role to create a user without being asked.');

            return self::FAILURE;
        }

        $details = $this->details();

        if ($details === false) {
            return self::FAILURE;
        }

        $role = $this->role($roles);

        if ($role === null) {
            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $details['name'],
            'email' => $details['email'],
            'password' => $details['password'],
            'is_active' => true,
        ]);

        // Made by someone with access to the server: the address needs no confirming.
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role->name]);

        $this->components->info("Created {$user->email} as {$role->displayName()}. They can sign in at ".Filament::getPanel('admin')->getLoginUrl());

        return self::SUCCESS;
    }

    /**
     * The new user's name, email address and password, as make:filament-user asks for them:
     * false when one that was passed isn't usable.
     *
     * @return array{name: string, email: string, password: string}|false
     */
    protected function details(): array|false
    {
        foreach (['email' => $this->emailProblem(...), 'password' => $this->passwordProblem(...)] as $option => $problemWith) {
            if (filled($given = $this->option($option)) && ($problem = $problemWith((string) $given)) !== null) {
                $this->components->error($problem);

                return false;
            }
        }

        return [
            'name' => $this->option('name') ?: text('Name', required: true),
            'email' => $this->option('email') ?: text('Email address', required: true, validate: $this->emailProblem(...)),
            'password' => $this->option('password') ?: password('Password', required: true, validate: $this->passwordProblem(...), hint: 'At least 8 characters.'),
        ];
    }

    /**
     * The role to give the user: from --role by name, else picked from the roles there are.
     * Null for a role that doesn't exist.
     *
     * @param  Collection<int, Role>  $roles
     */
    protected function role(Collection $roles): ?Role
    {
        if (filled($given = $this->option('role'))) {
            $role = $roles->first(fn (Role $role): bool => strcasecmp($role->name, (string) $given) === 0);

            if ($role === null) {
                $this->components->error("There is no role \"{$given}\". Use one of: ".$roles->pluck('name')->implode(', ').'.');
            }

            return $role;
        }

        // The first account runs the site; after that, most people who are added write and edit.
        $default = User::query()->exists() ? $roles->firstWhere('name', 'Editor') : $roles->firstWhere('name', User::SUPER_ADMIN);

        $key = select(
            'Which role should the user have?',
            $roles->mapWithKeys(fn (Role $role): array => [$role->getKey() => $role->displayName()])->all(),
            default: $default?->getKey(),
            hint: 'What they may do in the admin. Roles can be changed later under Users.',
        );

        return $roles->firstWhere($roles->first()->getKeyName(), $key);
    }

    /**
     * Why an email address can't be used, as make:filament-user words it: null when it can.
     */
    protected function emailProblem(string $email): ?string
    {
        return match (true) {
            ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'The email address must be valid.',
            User::query()->where('email', $email)->exists() => 'A user with this email address already exists.',
            default => null,
        };
    }

    protected function passwordProblem(string $password): ?string
    {
        return mb_strlen($password) < 8 ? 'The password must be at least 8 characters.' : null;
    }
}
