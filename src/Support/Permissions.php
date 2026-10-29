<?php

namespace Aurora\Support;

use Aurora\Models\Permission;
use Aurora\Models\Role;
use Aurora\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every permission the application checks, grouped and explained the way the role
 * editor shows them: Aurora's, then the website's own (register()).
 *
 * Names follow the roles & permissions plugin's convention ("{ability} {Model}") so its
 * permission generator and this list never disagree. Super Admins bypass all of them.
 */
class Permissions
{
    /**
     * @var array<string, array{icon: string, description: string, permissions: array<string, array{0: string, 1: string}>, grantTo: array<int, string>}>
     */
    protected static array $registered = [];

    /**
     * A group of the website's own permissions (its events, its shop …), in Users & roles beside
     * Aurora's, for the website's policies to check (`$user->can('update Event')`). Named
     * "{ability} {Model}" as Aurora's are. $grantTo: the built-in roles that get them when they
     * first exist (aurora:install, aurora:sync-permissions); after that, Users & roles decides.
     * Registered from a service provider; registering a group again replaces it.
     *
     * @param  array<string, array{0: string, 1: string}>  $permissions  name => [label, what it lets people do]
     * @param  array<int, string>  $grantTo
     */
    public static function register(string $group, array $permissions, string $icon = 'phosphor-puzzle-piece', string $description = '', array $grantTo = []): void
    {
        static::$registered[$group] = ['icon' => $icon, 'description' => $description, 'permissions' => $permissions, 'grantTo' => $grantTo];
    }

    /**
     * Take a group away again (a test).
     */
    public static function forget(string $group): void
    {
        unset(static::$registered[$group]);
    }

    /**
     * Every permission in the database: the website's new ones are created, and given to the
     * built-in roles they're granted to as they're created, never again, so a role an
     * administrator changed stays as they left it. After a website adds permissions to a site
     * that's running (aurora:sync-permissions), and before roles are edited.
     */
    public static function sync(): void
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $new = array_values(array_diff(static::all(), $existing));

        foreach ($new as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (static::$registered as $group) {
            foreach ($group['grantTo'] as $role) {
                $granted = array_values(array_intersect(array_keys($group['permissions']), $new));

                if ($granted !== [] && ($model = Role::query()->where('name', $role)->where('guard_name', 'web')->first()) !== null) {
                    $model->givePermissionTo($granted);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, array{icon: string, description: string, permissions: array<string, array{0: string, 1: string}>}>
     */
    public static function catalogue(): array
    {
        return [
            ...static::builtIn(),
            ...array_map(fn (array $group): array => array_diff_key($group, ['grantTo' => true]), static::$registered),
        ];
    }

    /**
     * @return array<string, array{icon: string, description: string, permissions: array<string, array{0: string, 1: string}>}>
     */
    protected static function builtIn(): array
    {
        return [
            'Pages' => [
                'icon' => 'phosphor-file-text',
                'description' => 'Writing, editing and publishing the website\'s pages.',
                'permissions' => [
                    'view-any Page' => ['See pages', 'Open the page list and read any page.'],
                    'create Page' => ['Create pages', 'Start new pages, blank or from a template.'],
                    'update Page' => ['Edit own pages', 'Change pages they wrote themselves.'],
                    'update-any Page' => ['Edit everyone\'s pages', 'Change any page, whoever wrote it.'],
                    'publish Page' => ['Publish pages', 'Put pages live, schedule and unpublish them.'],
                    'delete Page' => ['Delete pages', 'Move pages to the trash.'],
                    'restore Page' => ['Restore pages', 'Bring pages back from the trash.'],
                    'force-delete Page' => ['Delete permanently', 'Remove trashed pages for good.'],
                    // No role has it by default: only Super Admins (who have every permission).
                    'manage-search Page' => ['Manage search', 'See what search indexes for a page, and index pages again.'],
                ],
            ],
            'Media' => [
                'icon' => 'phosphor-image',
                'description' => 'The shared library of images used across the site.',
                'permissions' => [
                    'view-any Asset' => ['Use the media library', 'Browse, pick and upload images.'],
                    'update Asset' => ['Manage the media library', 'Edit alt text and captions, replace and delete images.'],
                    'manage-access Asset' => ['Change who has access', 'Choose who can see and change each file and folder.'],
                ],
            ],
            'Forms' => [
                'icon' => 'phosphor-clipboard-text',
                'description' => 'Contact and sign-up forms, and what people send through them.',
                'permissions' => [
                    'view-any Form' => ['See forms and submissions', 'Read and triage what people send through the forms.'],
                    'update Form' => ['Build forms', 'Create forms, change their fields and who gets notified.'],
                ],
            ],
            'Menus' => [
                'icon' => 'phosphor-text-align-left',
                'description' => 'The main navigation, footer and legal links.',
                'permissions' => [
                    'view-any Menu' => ['See menus', 'Open the menus.'],
                    'update Menu' => ['Edit menus', 'Add, reorder, nest and remove menu items.'],
                ],
            ],
            'Redirects' => [
                'icon' => 'phosphor-arrow-u-up-right',
                'description' => 'Old addresses that forward to new ones.',
                'permissions' => [
                    'view-any Redirect' => ['See redirects', 'Open the redirect list.'],
                    'update Redirect' => ['Manage redirects', 'Create, change and remove redirects.'],
                ],
            ],
            'People' => [
                'icon' => 'phosphor-users',
                'description' => 'Who has an account, and what they can do.',
                'permissions' => [
                    'view-any User' => ['See users', 'Open the user list.'],
                    'create User' => ['Invite users', 'Create new accounts.'],
                    'update User' => ['Edit users', 'Change accounts and their roles (not Super Admins).'],
                    'delete User' => ['Delete users', 'Remove accounts (not Super Admins).'],
                    'view-any Role' => ['See roles', 'Open this roles screen.'],
                    'update Role' => ['Manage roles', 'Create roles and change their permissions.'],
                    'impersonate User' => ['Impersonate users', 'Sign in as someone else to see exactly what they see (never a Super Admin). Not granted to any role by default.'],
                ],
            ],
            'Site & AI' => [
                'icon' => 'phosphor-sparkle',
                'description' => 'Site-wide settings, the AI assistant and external AI apps.',
                'permissions' => [
                    'manage-settings' => ['Site settings', 'Change the site name, brand colours, header and footer.'],
                    'view-any Activity' => ['See the activity log', 'See who changed what across the CMS. Not granted to any role by default.'],
                    'manage-sites' => ['Manage sites', 'Add sites, set their domains and languages, and choose who works on each. Sees every site.'],
                    'manage-languages' => ['Manage languages', 'Add languages, choose the website\'s language and switch languages on or off.'],
                    'translate' => ['Translate labels', 'Edit and translate every label in the website and the admin panel.'],
                    'use-assistant' => ['Use the AI assistant', 'Chat with the assistant in the admin panel. It reads and edits content with this role\'s permissions.'],
                    'use-mcp' => ['Connect external AI apps', 'Connect Claude Desktop or another MCP client from outside the admin panel, acting with this role\'s permissions.'],
                    'use-code-blocks' => ['Custom code blocks', 'Add raw HTML, CSS and JavaScript to pages. Grant with care.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function grouped(): array
    {
        return array_map(fn (array $group): array => array_keys($group['permissions']), static::catalogue());
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(static::grouped()));
    }

    public static function label(string $permission): string
    {
        foreach (static::catalogue() as $group) {
            if (isset($group['permissions'][$permission])) {
                return $group['permissions'][$permission][0];
            }
        }

        return $permission;
    }

    /**
     * @return array<string, array{description: string, permissions: array<int, string>}>
     */
    public static function roles(): array
    {
        $roles = [
            User::SUPER_ADMIN => [
                'description' => 'Everything, always. Including roles, users and settings.',
                'permissions' => [],
            ],
            'Editor' => [
                'description' => 'Edits and publishes every page, and manages menus and redirects.',
                'permissions' => [
                    'view-any Page', 'create Page', 'update Page', 'update-any Page', 'publish Page', 'delete Page', 'restore Page',
                    'view-any Menu', 'update Menu', 'view-any Redirect', 'update Redirect', 'use-assistant', 'use-mcp', 'translate',
                    'view-any Asset', 'update Asset', 'manage-access Asset', 'view-any Form', 'update Form',
                ],
            ],
            'Author' => [
                'description' => 'Writes their own pages and sends them to an editor for review.',
                'permissions' => ['view-any Page', 'create Page', 'update Page', 'use-assistant', 'use-mcp', 'view-any Asset'],
            ],
            'Viewer' => [
                'description' => 'Can look around, but not change anything.',
                'permissions' => ['view-any Page', 'view-any Menu'],
            ],
        ];

        // The website's own permissions, for the roles they're granted to.
        foreach (static::$registered as $group) {
            foreach ($group['grantTo'] as $role) {
                if (isset($roles[$role])) {
                    $roles[$role]['permissions'] = [...$roles[$role]['permissions'], ...array_keys($group['permissions'])];
                }
            }
        }

        return $roles;
    }
}
