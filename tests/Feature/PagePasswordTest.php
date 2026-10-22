<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Providers\AuroraServiceProvider;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class PagePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_password_protection_on_asks_for_the_password_and_saves_it_hashed(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'members']);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['password_protected' => true])
            ->assertActionMounted(TestAction::make('setPassword')->schemaComponent('password_protected', schema: 'form'))
            ->setActionData(['password' => 'open sesame', 'password_confirmation' => 'something else'])
            ->callMountedAction()
            ->assertHasActionErrors(['password_confirmation'])
            ->setActionData(['password' => 'open sesame', 'password_confirmation' => 'open sesame'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();
        $this->assertNotSame('open sesame', $page->password);
        $this->assertTrue(Hash::check('open sesame', $page->password));
        $this->assertArrayNotHasKey('password', $page->toArray());
    }

    public function test_protection_needs_a_password_and_switching_it_off_removes_it(): void
    {
        $unprotected = Page::factory()->published()->create(['slug' => 'members']);
        $protected = Page::factory()->published()->create(['slug' => 'staff', 'password' => 'open sesame']);
        $this->actingAs($this->userWithRole('Editor'));

        // Switched on, dialog closed without a password: not saved.
        Livewire::test(EditPage::class, ['record' => $unprotected->getKey()])
            ->fillForm(['password_protected' => true])
            ->call('unmountAction')
            ->call('save')
            ->assertHasFormErrors(['password_protected']);
        $this->assertFalse($unprotected->refresh()->isPasswordProtected());

        // Saving something else keeps the password; switching it off removes it.
        Livewire::test(EditPage::class, ['record' => $protected->getKey()])
            ->assertSchemaStateSet(['password_protected' => true, 'password' => null])
            ->fillForm(['title' => 'Staff only'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('open sesame', $protected->refresh()->password));

        Livewire::test(EditPage::class, ['record' => $protected->getKey()])
            ->fillForm(['password_protected' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($protected->refresh()->isPasswordProtected());
    }

    public function test_visitors_see_the_password_form_until_they_type_the_password(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'members', 'password' => 'open sesame']);
        $this->get('/members')
            ->assertOk()
            ->assertSee('This page is password protected')
            ->assertSee('noindex', false)
            ->assertDontSee('Hello')
            // A page of its own: not the site's header and footer.
            ->assertDontSee('<header', false)
            ->assertDontSee('<footer', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->from('/members')->post(route('pages.unlock', $page), ['password' => 'wrong'])
            ->assertRedirect('/members')
            ->assertSessionHasErrors(['password'], errorBag: 'page_password');
        $this->get('/members')->assertSee('This page is password protected');

        $this->from('/members')->post(route('pages.unlock', $page), ['password' => 'open sesame'])->assertRedirect('/members');
        $this->get('/members')->assertOk()->assertDontSee('This page is password protected');

        // A new password locks it again.
        $page->update(['password' => 'new one']);
        $this->get('/members')->assertSee('This page is password protected');
    }

    public function test_being_signed_in_does_not_open_the_page_but_a_preview_link_does(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'members', 'password' => 'open sesame']);
        $draft = Page::factory()->create(['slug' => 'plans', 'password' => 'open sesame']);

        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));
        $this->get('/members')->assertOk()->assertSee('This page is password protected');
        $this->get('/plans')->assertOk()->assertSee('This page is password protected');

        $this->get($page->previewUrl())->assertOk()->assertDontSee('This page is password protected');
    }

    public function test_a_protected_page_is_left_out_of_search_and_the_sitemap(): void
    {
        $page = Page::factory()->published()->create(['slug' => 'members', 'title' => 'Secret plans']);
        $this->assertSame([$page->id], Page::search('secret')->get()->modelKeys());

        $page->update(['password' => 'open sesame']);

        $this->assertNull($page->refresh()->search_text);
        $this->assertSame([], Page::searchSite('secret', liveOnly: false)->get()->modelKeys());
        $this->get('/sitemap.xml')->assertDontSee('/members');
    }

    public function test_the_page_list_shows_a_key_by_a_protected_pages_title(): void
    {
        Page::factory()->published()->create(['title' => 'Open house']);
        Page::factory()->published()->create(['title' => 'Members', 'password' => 'open sesame']);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListPages::class)
            ->assertSeeHtml('Members <span class="cms-page-title-key"')
            ->assertDontSeeHtml('Open house <span class="cms-page-title-key"');
    }

    public function test_sites_can_publish_the_password_page_to_make_it_their_own(): void
    {
        $this->assertContains(
            resource_path('views/vendor/aurora/page-password.blade.php'),
            ServiceProvider::pathsToPublish(AuroraServiceProvider::class, 'aurora-views'),
        );
    }
}
