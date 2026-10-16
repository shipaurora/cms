<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Models\Locale;
use Aurora\Support\TranslationSync;
use Illuminate\Database\Seeder;

class LocaleSeeder extends Seeder
{
    /**
     * English is the website's language; Norwegian is available too. Every label, the app's,
     * Laravel's and the packages', is imported from the lang files (lang/*.json, lang/nb,
     * lang/vendor), which are its source.
     */
    public function run(): void
    {
        Locale::query()->updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_default' => true, 'is_active' => true, 'sort' => 1]);
        Locale::query()->updateOrCreate(['code' => 'nb'], ['name' => 'Norsk bokmål', 'is_active' => true, 'sort' => 2]);

        app(TranslationSync::class)->import();
    }
}
