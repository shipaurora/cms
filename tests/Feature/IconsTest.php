<?php

namespace Aurora\Tests\Feature;

use Aurora\Aurora;
use Aurora\Support\AdminIcons;
use Aurora\Support\BlockCatalogue;
use Aurora\Support\SiteIcon;
use Aurora\Tests\TestCase;

class IconsTest extends TestCase
{
    public function test_every_heroicon_has_a_phosphor_icon_in_every_weight(): void
    {
        $heroicons = collect(scandir(Aurora::packagePath('blade-ui-kit/blade-heroicons', 'resources/svg')))
            ->filter(fn (string $file): bool => str_starts_with($file, 'o-'))
            ->map(fn (string $file): string => substr($file, 2, -4))
            ->values();

        $this->assertEqualsCanonicalizing($heroicons->all(), array_keys(AdminIcons::MAP));

        foreach (AdminIcons::MAP as $phosphor) {
            foreach (AdminIcons::WEIGHTS as $weight) {
                $this->assertFileExists(Aurora::packagePath('codeat3/blade-phosphor-icons', "resources/svg/{$phosphor}{$weight}.svg"));
            }
        }
    }

    public function test_the_admin_draws_phosphor_and_the_website_keeps_heroicons(): void
    {
        $phosphor = file_get_contents(Aurora::packagePath('codeat3/blade-phosphor-icons', 'resources/svg/house.svg'));
        $heroicon = file_get_contents(Aurora::packagePath('blade-ui-kit/blade-heroicons', 'resources/svg/o-home.svg'));

        $this->assertStringContainsString($this->firstPath($phosphor), svg('heroicon-o-home')->toHtml());
        $this->assertStringContainsString($this->firstPath($heroicon), svg('hero-o-home')->toHtml());
    }

    public function test_stored_icon_names_resolve_to_website_icons(): void
    {
        $this->assertSame('hero-o-sparkles', SiteIcon::name('hero-o-sparkles'));
        $this->assertSame('hero-o-sparkles', SiteIcon::name('sparkles'));
        $this->assertSame('hero-m-sparkles', SiteIcon::name('sparkles', style: 'm'));
        $this->assertSame('hero-o-home', SiteIcon::name('heroicon-o-home'));
        $this->assertNull(SiteIcon::name('no-such-icon'));
        $this->assertNull(SiteIcon::name(null));
    }

    public function test_assistants_must_use_icons_that_exist(): void
    {
        $this->assertSame([], BlockCatalogue::validate('feature', ['icon' => 'hero-o-rocket-launch']));
        $this->assertNotSame([], BlockCatalogue::validate('feature', ['icon' => 'hero-o-no-such-icon']));
    }

    protected function firstPath(string $svg): string
    {
        preg_match('/ d="([^"]{1,40})/', $svg, $matches);

        return $matches[1];
    }
}
