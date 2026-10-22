<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\User;
use Aurora\Support\SystemInfo;
use Aurora\Support\Updates;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The release installed here, as far as the tests go.
     */
    protected string $installed = 'v1.0.4';

    protected function setUp(): void
    {
        parent::setUp();

        Updates::fakeInstalled($this->installed);
        Updates::forget();
    }

    protected function tearDown(): void
    {
        Updates::fakeInstalled(null);

        parent::tearDown();
    }

    public function test_system_info_says_when_a_newer_aurora_is_out_and_the_settings_menu_shows_it(): void
    {
        $newer = $this->newer();
        $this->packagistLists([$newer, $this->installed, 'v99.0.0-beta1', 'dev-main']);

        $aurora = SystemInfo::rows()[0];

        $this->assertSame(['label' => "Update available: {$newer}", 'color' => 'warning'], $aurora['badge']);
        $this->assertStringContainsString("compare/{$this->installed}...{$newer}", $aurora['url']);

        // Once known, the settings menu shows it too, without asking Packagist again.
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'system'])
            ->test(SiteSettings::class)
            ->assertSee("Update available: {$newer}")
            ->assertSee('composer update shipaurora/cms');

        $this->assertSame('Update', collect((new SiteSettings)->getSubNavigation())->last()->getBadge());
        Http::assertSentCount(1);
    }

    public function test_it_says_up_to_date_when_the_newest_release_is_installed(): void
    {
        $this->packagistLists([$this->installed, 'v0.9.0']);

        $this->assertSame(['label' => 'Up to date', 'color' => 'success'], SystemInfo::rows()[0]['badge']);
        $this->assertNull(collect((new SiteSettings)->getSubNavigation())->last()->getBadge());
    }

    public function test_without_packagist_it_says_nothing_and_asks_again_later_not_every_time(): void
    {
        Http::fake([Updates::METADATA_URL => Http::response(null, 503)]);

        $this->assertArrayNotHasKey('badge', SystemInfo::rows()[0]);
        $this->assertArrayNotHasKey('badge', SystemInfo::rows()[0]);

        Http::assertSentCount(1);
    }

    /**
     * @param  array<int, string>  $versions
     */
    protected function packagistLists(array $versions): void
    {
        Http::fake([Updates::METADATA_URL => Http::response(['packages' => [Updates::PACKAGE => array_map(fn (string $version): array => ['version' => $version], $versions)]])]);
    }

    /**
     * A release after the installed one.
     */
    protected function newer(): string
    {
        [$major, $minor, $patch] = explode('.', ltrim($this->installed, 'v'));

        return 'v'.$major.'.'.$minor.'.'.((int) $patch + 1);
    }
}
