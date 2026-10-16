<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class UserSeeder extends Seeder
{
    /**
     * Demo accounts, one per role, all with the password "password", and a mix of admin
     * panel languages.
     */
    public const USERS = [
        ['Thomas Admin', 'admin@example.com', 'Super Admin', 'Head of Digital', 'avatars/thomas.jpg', 'en'],
        ['Ingrid Editor', 'editor@example.com', 'Editor', 'Content Lead', 'person1.jpg', 'nb'],
        ['Aksel Author', 'author@example.com', 'Author', 'Copywriter', 'person4.jpg', 'en'],
        ['Vera Viewer', 'viewer@example.com', 'Viewer', 'Sales', 'person5.jpg', 'nb'],
    ];

    public function run(): void
    {
        foreach (self::USERS as [$name, $email, $role, $title, $avatar, $locale]) {
            // Portraits in seeders/images/avatars are for people only (the rest of seeders/images
            // goes into the media library); without one, the person is shown with their initials.
            $source = __DIR__.'/images/'.$avatar;
            $avatar = file_exists($source) ? 'avatars/'.basename($avatar) : null;

            if ($avatar !== null) {
                Storage::disk('public')->put($avatar, file_get_contents($source));
            }

            $user = User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'job_title' => $title,
                'avatar' => $avatar,
                'locale' => $locale,
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role]);
        }
    }
}
