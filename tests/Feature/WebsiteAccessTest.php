<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\WebsiteAccess;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class WebsiteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_only_for_signed_in_people_asks_the_others_to_sign_in(): void
    {
        Page::factory()->homepage()->create();
        $page = Page::factory()->published()->create(['slug' => 'members', 'title' => 'Member news', 'members_only' => true]);

        $this->get('/members')->assertForbidden()->assertSee('Sign in to see this page')->assertDontSee('Hello');
        $this->get('/sitemap.xml')->assertDontSee('/members');
        $this->assertFalse($page->shouldBeSearchable());

        $this->actingAs(User::factory()->create())->get('/members')->assertOk()->assertSee('Member news')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_it_is_switched_on_in_the_page_form(): void
    {
        $page = Page::factory()->create();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['members_only' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($page->fresh()->members_only);
    }

    public function test_a_website_open_only_to_some_addresses_turns_the_others_away(): void
    {
        Page::factory()->homepage()->create(['title' => 'Welcome']);
        Setting::put([WebsiteAccess::SETTING => ['restricted' => true, 'addresses' => "203.0.113.7\n10.0.0.0/8"]]);

        $this->get('/', ['REMOTE_ADDR' => '198.51.100.1'])->assertForbidden()->assertSee('This website isn’t open yet');
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/')->assertOk()->assertSee('Welcome');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->get('/')->assertForbidden();

        // People signed in to the CMS always get in.
        $this->actingAs($this->userWithRole('Editor'))->get('/')->assertOk();
    }

    public function test_addresses_are_checked_in_settings(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'security', 'section' => 'access'])
            ->test(SiteSettings::class)
            ->fillForm([WebsiteAccess::SETTING => ['restricted' => true, 'addresses' => "203.0.113.7\nnot-an-address"]])
            ->call('save')
            ->assertHasFormErrors([WebsiteAccess::SETTING.'.addresses'])
            ->fillForm([WebsiteAccess::SETTING => ['restricted' => true, 'addresses' => "203.0.113.7\n10.0.0.0/8"]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(WebsiteAccess::restricted());
        $this->assertSame(['203.0.113.7', '10.0.0.0/8'], WebsiteAccess::addresses());
    }
}
