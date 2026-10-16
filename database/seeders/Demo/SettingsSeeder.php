<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Support\Copilot;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::put([
            'site_name' => 'Aurora',
            'tagline' => 'The CMS your editors enjoy and your developers trust. Built on Laravel and Filament.',
            'brand_color' => '#7c3aed',
            'accent_color' => '#22d3ee',
            'header_cta_label' => 'Get started',
            'header_cta_page' => Page::query()->whereSlug('getting-started')->value('id'),
            'footer_note' => 'Built with Aurora, naturally.',
            'contact_email' => 'hello@aurora.test',
            'socials' => [
                ['network' => 'LinkedIn', 'url' => 'https://www.linkedin.com'],
                ['network' => 'GitHub', 'url' => 'https://github.com'],
            ],
            // The AI assistant starts switched off (no provider or key), with good instructions ready.
            'copilot' => ['instructions' => Copilot::DEFAULT_INSTRUCTIONS],
            'announcement' => [
                'enabled' => true,
                'text' => 'Aurora 1.0: install it into any Laravel site, with passkeys and two-factor sign-in.',
                'link_label' => 'What\'s new',
                'url' => '/release-notes',
            ],
        ]);
    }
}
