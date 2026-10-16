<?php

use Aurora\Models\Permission;
use Aurora\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Languages and translations get their own permissions; editors may translate.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::findOrCreate('manage-languages', 'web');
        $translate = Permission::findOrCreate('translate', 'web');

        Role::query()->where('name', 'Editor')->first()?->givePermissionTo($translate);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
