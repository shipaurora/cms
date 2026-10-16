<?php

namespace Aurora\Database\Seeders;

use Aurora\Models\Permission;
use Aurora\Models\Role;
use Aurora\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Permissions::roles() as $name => $role) {
            $model = Role::findOrCreate($name, 'web');

            // In every language the admin panel speaks, keeping what someone already changed.
            foreach (Role::adminLocales() as $locale) {
                if (blank($model->getTranslation('label', $locale, false))) {
                    $model->setTranslation('label', $locale, __($name, [], $locale));
                }

                if (blank($model->getTranslation('description', $locale, false))) {
                    $model->setTranslation('description', $locale, __($role['description'], [], $locale));
                }
            }

            $model->saveQuietly();
            $model->syncPermissions($role['permissions']);
        }
    }
}
