<?php

namespace Aurora\Tests\Feature;

use Aurora\Aurora;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\Themes;
use Aurora\Tests\TestCase;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Usamamuneerchaudhary\CommandPalette\Support\PaletteSettings;

class AdminThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Themes::forget('acme');

        parent::tearDown();
    }

    public function test_themes_register_themselves_and_borealis_is_used_until_another_is_chosen(): void
    {
        $this->assertSame(['borealis'], array_keys(Themes::all()));
        $this->assertSame('borealis', Themes::active());

        // Its prebuilt stylesheet: nothing for the app to compile.
        $this->assertStringContainsString('/css/shipaurora/borealis-theme/borealis.css', Themes::html());
        // Its address carries the theme's own release, not Filament's, so an update reaches browsers.
        $release = ltrim(InstalledVersions::getPrettyVersion('shipaurora/borealis'), 'v');
        $this->assertStringContainsString('borealis.css?v='.$release.'"', Themes::html());

        // A theme an app builds with Vite, chosen in Settings: its entry is loaded instead.
        $public = sys_get_temp_dir().'/aurora-theme-'.Str::random(8);
        File::ensureDirectoryExists($public.'/build');
        File::put($public.'/build/manifest.json', json_encode(['resources/css/app.css' => ['file' => 'assets/app-4f2a.css', 'src' => 'resources/css/app.css', 'isEntry' => true]]));
        $this->app->usePublicPath($public);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($public));
        Themes::register('acme', name: 'Acme', vite: 'resources/css/app.css');
        Setting::put([Themes::SETTING => 'acme']);
        $this->assertSame('acme', Themes::active());
        $this->assertStringContainsString('/build/assets/app-', Themes::html());

        // A theme that's gone (its package removed): back to Borealis.
        Setting::put([Themes::SETTING => 'removed']);
        $this->assertSame('borealis', Themes::active());
    }

    public function test_the_theme_is_chosen_for_the_whole_admin_in_settings(): void
    {
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, package: 'acme/admin-theme');
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'appearance'])
            ->test(SiteSettings::class)
            ->assertSee('Acme')
            ->fillForm([Themes::SETTING => 'acme'])
            ->call('save')
            ->assertHasNoFormErrors()
            // Its stylesheet is in the page's head: the page is loaded again.
            ->assertRedirect(SiteSettings::getUrl(['tab' => 'appearance']));

        $this->assertSame('acme', Themes::active());

        Livewire::withQueryParams(['tab' => 'appearance'])
            ->test(SiteSettings::class)
            ->fillForm([Themes::SETTING => 'unknown'])
            ->call('save')
            ->assertHasFormErrors([Themes::SETTING]);
    }

    public function test_each_person_can_pick_their_own_theme_and_otherwise_gets_the_websites(): void
    {
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, package: 'acme/admin-theme');
        $editor = $this->userWithRole('Editor');
        $colleague = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertSee('Same as the website (Borealis)')
            ->fillForm(['admin_theme' => 'acme'])
            ->call('save')
            ->assertHasNoFormErrors()
            // Its stylesheet is in the page's head: the page is loaded again.
            ->assertRedirect(EditUser::getUrl(['record' => $editor]));

        $this->actingAs($editor->fresh());
        $this->assertSame('acme', Themes::active());
        $this->assertSame('borealis', Themes::siteDefault());

        // Everyone else keeps the website's theme, and a removed theme falls back to it.
        $this->actingAs($colleague);
        $this->assertSame('borealis', Themes::active());
        $colleague->forceFill(['admin_theme' => 'removed'])->save();
        $this->assertSame('borealis', Themes::active());

        Livewire::actingAs($editor->fresh())
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->fillForm(['admin_theme' => 'unknown'])
            ->call('save')
            ->assertHasFormErrors(['admin_theme']);
    }

    public function test_the_admin_theme_is_only_offered_on_your_own_profile_and_with_more_than_one_theme(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $editor = $this->userWithRole('Editor');

        // Only Borealis: nothing to choose.
        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertFormFieldHidden('admin_theme');

        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, package: 'acme/admin-theme');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertFormFieldVisible('admin_theme');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertFormFieldHidden('admin_theme');
    }

    public function test_each_theme_shows_its_version_beside_its_name(): void
    {
        // Borealis's assets go by another name than its package: it's found by where its stylesheet is.
        $borealis = 'v'.ltrim((string) InstalledVersions::getPrettyVersion('shipaurora/borealis'), 'v');
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, package: 'acme/admin-theme', version: '2.1.0');
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $this->assertSame($borealis, Themes::version('borealis'));
        $this->assertSame('v2.1.0', Themes::version('acme'));

        Livewire::withQueryParams(['tab' => 'appearance'])
            ->test(SiteSettings::class)
            ->assertSee($borealis)
            ->assertSee('v2.1.0');

        // A theme the app builds itself has no release to show.
        Themes::register('acme', name: 'Acme', vite: 'resources/css/app.css');
        $this->assertNull(Themes::version('acme'));
    }

    public function test_each_prebuilt_theme_styles_every_class_the_installed_filament_styles(): void
    {
        // A theme's stylesheet is compiled from one Filament release. A newer Filament's markup can
        // use classes that release didn't have: Filament 5.11 put dashboard widgets in a
        // .fi-sc-liberated wrapper, and a theme built on 5.10 drew every widget one column wide.
        $classes = fn (string $css): array => array_unique(preg_match_all('/\.(fi-[\w-]+)/', $css, $matches) ? $matches[1] : []);
        $filament = $classes(File::get(Aurora::packagePath('filament/filament', 'dist/theme.css')));
        $themes = array_filter(Themes::all(), fn (array $theme): bool => $theme['stylesheet'] !== null);

        $this->assertNotEmpty($filament);
        $this->assertArrayHasKey('borealis', $themes);

        foreach ($themes as $id => $theme) {
            $missing = array_values(array_diff($filament, $classes(File::get($theme['stylesheet']))));

            $this->assertSame([], $missing, "The {$id} theme was built with an older Filament: rebuild its theme.css and release it.");
        }
    }

    public function test_the_appearance_settings_link_to_more_themes_in_a_new_tab(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $html = Livewire::withQueryParams(['tab' => 'appearance'])
            ->test(SiteSettings::class)
            ->assertSee('Browse themes')
            ->html();

        $this->assertMatchesRegularExpression('#<a\b[^>]*href="'.preg_quote(e(Themes::BROWSE_URL), '#').'"[^>]*target="_blank"#s', $html);
    }

    public function test_people_switch_between_light_and_dark_only_when_the_theme_has_both(): void
    {
        $panel = Filament::getPanel('admin');
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        // Borealis has both: the switch is there, and each person's choice is kept.
        $this->assertSame(['light', 'dark'], Themes::modes());
        $this->assertTrue($panel->hasThemeSwitcher());
        $this->assertSame('light', Themes::modeFor('light'));

        // A dark-only theme: always dark, nothing to switch (nor in ⌘K).
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, modes: ['dark']);
        Setting::put([Themes::SETTING => 'acme']);
        $this->assertFalse($panel->hasThemeSwitcher());
        $this->assertTrue($panel->hasDarkModeForced());
        $this->assertSame('dark', Themes::modeFor('light'));
        $this->assertFalse(PaletteSettings::for($panel)->array('built_in_commands')['theme']);
        $this->get(SiteSettings::getUrl())->assertDontSee('fi-theme-switcher', escape: false);

        // A light-only one: no dark mode at all.
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, modes: ['light']);
        $this->assertFalse($panel->hasDarkMode());
        $this->assertSame('light', Themes::modeFor('dark'));
    }

    public function test_a_theme_s_picture_is_shown_to_people_who_manage_settings(): void
    {
        $url = route('aurora.themes.preview', 'borealis');

        $this->get($url)->assertRedirect();
        $this->actingAs($this->userWithRole('Editor'))->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN))->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get(route('aurora.themes.preview', 'unknown'))->assertNotFound();
    }

    public function test_a_theme_with_a_picture_per_mode_shows_the_one_for_the_mode_people_are_in(): void
    {
        $light = Themes::all()['borealis']['previews']['light'];
        $dark = Themes::all()['borealis']['previews']['dark'];
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, preview: ['light' => $light, 'dark' => $dark]);
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        $this->assertSame($light, $this->get(route('aurora.themes.preview', ['acme', 'light']))->baseResponse->getFile()->getPathname());
        $this->assertSame($dark, $this->get(route('aurora.themes.preview', ['acme', 'dark']))->baseResponse->getFile()->getPathname());

        // Both are in the picker; the admin's stylesheet shows the one for the mode it's in.
        Livewire::withQueryParams(['tab' => 'appearance'])
            ->test(SiteSettings::class)
            ->assertSeeHtml('cms-theme-preview-light')
            ->assertSeeHtml('cms-theme-preview-dark');

        // A dark-only theme with a light picture: shown in the mode it has.
        Themes::register('acme', name: 'Acme', stylesheet: __FILE__, preview: ['light' => $light], modes: ['dark']);
        $this->assertSame(['dark' => $light], Themes::all()['acme']['previews']);
    }
}
