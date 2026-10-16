<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Database\Seeders\EssentialSeeder;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // The demo: what every installation has, plus Norwegian, demo people, pages, media,
        // forms, menus, settings and the notifications they would have sent (php artisan aurora:install --demo).
        $this->call([
            EssentialSeeder::class,
            LocaleSeeder::class,
            UserSeeder::class,
            PageSeeder::class,
            SettingsSeeder::class,
            MenuSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
