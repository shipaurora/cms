<?php

namespace Aurora\Database\Seeders;

use Aurora\Models\Locale;
use Aurora\Models\Setting;
use Aurora\Support\Copilot;
use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;
use RuntimeException;

class EssentialSeeder extends Seeder
{
    /**
     * What every installation needs to work, demo or not: roles and permissions, a language for
     * the website (English unless one was chosen), the client that issues AI app tokens, and default settings.
     * Safe to run again: nothing is created twice and existing settings are kept.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // English, unless the site already has its languages (aurora:install asks which one).
        if (! Locale::query()->exists()) {
            Locale::query()->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort' => 1]);
        }

        // Issues the personal access tokens created on the "Connect AI apps" page.
        // (OAuth clients such as Claude register themselves when they first connect.)
        $clients = app(ClientRepository::class);

        try {
            $clients->personalAccessClient('users');
        } catch (RuntimeException) {
            $clients->createPersonalAccessGrantClient('Personal access tokens', 'users');
        }

        // The AI assistant starts switched off (no provider or key), with good instructions ready.
        Setting::put(array_filter([
            'site_name' => Setting::get('site_name') === null ? config('app.name') : null,
            'copilot' => Setting::get('copilot') === null ? ['instructions' => Copilot::DEFAULT_INSTRUCTIONS] : null,
        ]));
    }
}
