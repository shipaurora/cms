<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class HomepageToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_toggle_in_the_form_moves_the_homepage(): void
    {
        $this->actingAs($this->userWithRole('Editor'));

        $home = Page::factory()->homepage()->create();
        $about = Page::factory()->published()->create(['slug' => 'about']);

        Livewire::test(EditPage::class, ['record' => $about->getKey()])
            ->assertSchemaStateSet(['is_homepage' => false])
            ->fillForm(['is_homepage' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($about->refresh()->is_homepage);
        $this->assertFalse($home->refresh()->is_homepage);
        $this->get('/')->assertSee($about->title);
    }

    public function test_a_draft_cannot_become_the_homepage(): void
    {
        $this->actingAs($this->userWithRole('Editor'));

        $home = Page::factory()->homepage()->create();
        $draft = Page::factory()->create();

        Livewire::test(EditPage::class, ['record' => $draft->getKey()])
            ->fillForm(['is_homepage' => true])
            ->call('save')
            ->assertHasFormErrors(['is_homepage']);

        $this->assertTrue($home->refresh()->is_homepage);
        $this->assertFalse($draft->refresh()->is_homepage);
    }

    public function test_publishing_and_making_homepage_in_one_save_works(): void
    {
        $this->actingAs($this->userWithRole('Editor'));

        Page::factory()->homepage()->create();
        $draft = Page::factory()->create();

        Livewire::test(EditPage::class, ['record' => $draft->getKey()])
            ->fillForm(['status' => 'published', 'is_homepage' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($draft->refresh()->is_homepage);
    }

    public function test_the_homepage_can_go_back_to_draft_but_not_be_switched_off(): void
    {
        $this->actingAs($this->userWithRole('Editor'));

        $home = Page::factory()->homepage()->create();
        Page::factory()->published()->create(['title' => 'About us']);

        // Back to draft: allowed, and the form says what visitors see meanwhile.
        $home->update(['status' => PageStatus::Draft]);
        $this->assertSame(PageStatus::Draft, $home->fresh()->status);
        $this->assertTrue($home->fresh()->is_homepage);
        $this->assertStringContainsString('see “Page not found”', PageResource::withoutHomepage($home->fresh()));
        // No other page stands in for it.
        $this->get('/')->assertNotFound();

        Livewire::test(EditPage::class, ['record' => $home->getKey()])
            ->assertSee('The homepage won’t be live')
            ->assertActionVisible('design');

        // And from the form, back to review.
        $home->update(['status' => PageStatus::Published]);
        Livewire::test(EditPage::class, ['record' => $home->getKey()])
            ->fillForm(['status' => PageStatus::Review->value])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(PageStatus::Review, $home->fresh()->status);

        // Switching it off, though, still means choosing another homepage.
        $this->expectException(ValidationException::class);
        $home->update(['is_homepage' => false]);
    }

    public function test_mcp_explains_why_a_draft_cannot_be_the_homepage(): void
    {
        $editor = $this->userWithRole('Editor');
        Page::factory()->homepage()->create();
        $draft = Page::factory()->create();

        CmsServer::actingAs($editor)
            ->tool(UpdatePage::class, ['page' => $draft->id, 'is_homepage' => true])
            ->assertHasErrors(['The homepage must be published and live']);
    }

    public function test_the_homepage_form_hides_the_address_but_keeps_it(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        $home = Page::factory()->homepage()->create(['slug' => 'welcome']);
        $about = Page::factory()->published()->create(['slug' => 'about']);

        Livewire::test(EditPage::class, ['record' => $home->getKey()])
            ->assertFormFieldHidden('slug')
            // In its place: the same field, blank and disabled, at the site's own address.
            ->assertFormFieldVisible('homepage_address')
            ->assertFormFieldDisabled('homepage_address')
            ->fillForm(['title' => 'Welcome home'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('welcome', $home->refresh()->slug);

        // Made the homepage in the form: the address goes away there too.
        Livewire::test(EditPage::class, ['record' => $about->getKey()])
            ->assertFormFieldVisible('slug')
            ->assertFormFieldHidden('homepage_address')
            ->fillForm(['is_homepage' => true])
            ->assertFormFieldHidden('slug')
            ->assertFormFieldVisible('homepage_address')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('about', $about->refresh()->slug);
    }
}
