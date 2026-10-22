<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\Trash;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Filament\Resources\Redirects\Pages\ManageRedirects;
use Aurora\Models\Asset;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Models\User;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Livewire\Livewire;

class TrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_pages_media_and_forms_wait_in_the_trash(): void
    {
        Storage::fake('public');
        $page = Page::factory()->create(['title' => 'Old campaign']);
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 200, 100));
        $form = Form::factory()->create(['name' => 'Old survey']);

        $page->delete();
        $asset->delete();
        $form->delete();

        $this->assertNotNull($asset->fresh()?->file(), 'A trashed file keeps its file, ready to restore.');

        $trash = Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))->test(Trash::class);

        $this->get($page->previewUrl())->assertOk()->assertSee('Old campaign');

        $trash->assertCanSeeTableRecords([$page])
            ->assertTableActionVisible('preview', $page)
            ->callTableAction('restore', $page);

        $trash->set('kind', 'media')
            ->assertCanSeeTableRecords([$asset])
            ->callTableAction('forceDelete', $asset);

        $trash->set('kind', 'forms')
            ->assertCanSeeTableRecords([$form]);

        $this->assertFalse($page->fresh()->trashed());
        $this->assertNull(Asset::withTrashed()->find($asset->id));
        $this->assertTrue(Form::withTrashed()->find($form->id)->trashed());
        $this->assertSame('1', Trash::getNavigationBadge());
    }

    public function test_the_trash_shows_only_what_the_person_may_restore(): void
    {
        $this->actingAs($this->userWithRole('Author'));

        $this->assertFalse(Trash::canAccess());

        $this->actingAs($this->userWithRole('Editor'));

        $this->assertSame(['pages', 'media', 'forms'], array_keys(Trash::kinds()));
        $this->assertFalse(Trash::shouldRegisterNavigation(), 'Nothing in the trash: no sidebar item.');

        Page::factory()->create()->delete();
        Once::flush(); // A new request, as the sidebar's refresh after deleting is.

        $this->assertTrue(Trash::shouldRegisterNavigation());
    }

    public function test_the_homepage_can_be_deleted_with_a_warning_and_comes_back_as_an_ordinary_page_if_replaced(): void
    {
        $home = Page::factory()->homepage()->create();
        $other = Page::factory()->published()->create();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListPages::class)
            ->selectTableRecords([$home, $other])
            ->mountAction(TestAction::make('delete')->table()->bulk())
            ->tap(fn ($page) => $this->assertStringContainsString('is the homepage', (string) $page->instance()->getMountedAction()->getModalDescription()))
            ->callMountedAction();

        $this->assertTrue($home->fresh()->trashed());
        $this->assertTrue($other->fresh()->trashed());

        // Another page became the homepage meanwhile: restored, the old one is an ordinary page.
        $other->restore();
        $other->fresh()->update(['is_homepage' => true]);
        Page::withTrashed()->find($home->id)->restore();

        $this->assertFalse($home->fresh()->is_homepage);
        $this->assertSame([$other->id], Page::query()->homepage()->pluck('id')->all());
    }

    public function test_emptying_the_trash_deletes_everything_in_it_for_good(): void
    {
        $kept = Page::factory()->create();
        Page::factory()->count(2)->create()->each->delete();
        Form::factory()->create()->delete();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(Trash::class)
            ->assertActionVisible('emptyTrash')
            ->callAction('emptyTrash')
            ->assertNotified('3 items deleted for good')
            ->assertActionHidden('emptyTrash');

        $this->assertSame(1, Page::withTrashed()->count());
        $this->assertTrue($kept->exists);
        $this->assertSame(0, Form::withTrashed()->count());
    }

    public function test_a_redirect_to_a_trashed_page_says_so_and_leads_nowhere(): void
    {
        $page = Page::factory()->published()->create(['title' => 'Old news', 'slug' => 'news']);
        Redirect::query()->create(['from_path' => '/old-news', 'page_id' => $page->id, 'status_code' => 301]);
        $page->delete();

        $this->get('/old-news')->assertNotFound();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ManageRedirects::class)
            ->assertSee('Old news (in the trash)');
    }

    public function test_only_kinds_with_something_in_the_trash_get_a_tab(): void
    {
        $page = Page::factory()->create();
        $form = Form::factory()->create(['name' => 'Old survey']);
        $page->delete();
        $form->delete();

        $trash = Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))->test(Trash::class)
            ->assertSee(__('Pages'))
            ->assertSee(__('Forms'))
            ->assertDontSee(__('Media'));

        // Restoring the last page empties its tab: on to the forms.
        $trash->callTableAction('restore', $page)
            ->assertSet('kind', 'forms')
            ->assertCanSeeTableRecords([$form]);
    }
}
