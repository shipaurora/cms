<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\PublishPage;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Support\WorkingCopies;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class WorkingCopiesTest extends TestCase
{
    use RefreshDatabase;

    protected function livePage(): Page
    {
        Page::factory()->homepage()->create();

        return Page::factory()->published()->create(['title' => 'Prices', 'slug' => 'prices']);
    }

    public function test_saving_a_live_page_keeps_the_changes_off_the_website_until_they_re_published(): void
    {
        $page = $this->livePage();
        $editor = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'New prices'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved. Not on the website until you publish the changes.')
            // What's being edited is the working copy.
            ->assertSchemaStateSet(['title' => 'New prices'])
            ->assertActionVisible('publishChanges');

        $page->refresh();
        $this->assertTrue($page->hasWorkingCopy());
        $this->assertSame('Prices', $page->title);
        $this->assertTrue($page->workingCopyBy->is($editor));
        $this->get('/prices')->assertSee('Prices')->assertDontSee('New prices');

        // The preview shows them; the form opens with them.
        $this->get($page->previewUrl())->assertSee('New prices');
        Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertSchemaStateSet(['title' => 'New prices'])
            ->callAction('publishChanges')
            ->assertNotified('Changes published');

        $this->assertFalse($page->fresh()->hasWorkingCopy());
        $this->assertSame('New prices', $page->fresh()->title);
    }

    public function test_unpublished_changes_can_be_discarded(): void
    {
        $page = $this->livePage();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Not kept'])
            ->call('save')
            ->callAction(TestAction::make('discardChanges'))
            ->assertSchemaStateSet(['title' => 'Prices']);

        $this->assertFalse($page->fresh()->hasWorkingCopy());
        $this->assertSame('Prices', $page->fresh()->title);
    }

    public function test_a_draft_takes_changes_directly(): void
    {
        $page = Page::factory()->create(['title' => 'Draft', 'status' => PageStatus::Draft]);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Still a draft'])
            ->call('save');

        $this->assertFalse($page->fresh()->hasWorkingCopy());
        $this->assertSame('Still a draft', $page->fresh()->title);
    }

    public function test_only_publishers_put_the_changes_on_the_website(): void
    {
        $page = $this->livePage();
        $author = $this->userWithRole('Author');
        $page->update(['author_id' => $author->id]);

        Livewire::actingAs($author)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Author edit'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertActionHidden('publishChanges');

        $this->assertSame('Prices', $page->fresh()->title);
        $this->assertTrue($page->fresh()->hasWorkingCopy());
    }

    public function test_a_site_can_have_saved_changes_go_live_straight_away(): void
    {
        $page = $this->livePage();
        $editor = $this->userWithRole('Editor');

        // Waiting from before the setting was on: it goes live with the next save.
        Livewire::actingAs($editor)->test(EditPage::class, ['record' => $page->getKey()])->fillForm(['title' => 'New prices'])->call('save');
        Setting::put([WorkingCopies::SETTING => true]);

        Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['excerpt' => 'What it costs'])
            ->call('save')
            ->assertNotified('Saved');

        $page->refresh();
        $this->assertFalse($page->hasWorkingCopy());
        $this->assertSame(['New prices', 'What it costs'], [$page->title, $page->excerpt]);
        $this->get('/prices')->assertSee('New prices');

        // Someone who may not publish still only saves changes for someone else to publish.
        $author = $this->userWithRole('Author');
        $page->update(['author_id' => $author->id]);

        Livewire::actingAs($author)->test(EditPage::class, ['record' => $page->getKey()])->fillForm(['title' => 'Author edit'])->call('save')->assertHasNoErrors();

        $this->assertTrue($page->fresh()->hasWorkingCopy());
        $this->assertSame('New prices', $page->fresh()->title);
    }

    public function test_unpublished_changes_show_in_the_form_the_list_and_the_visual_editor(): void
    {
        $page = $this->livePage();
        $editor = $this->userWithRole('Editor');
        Livewire::actingAs($editor)->test(EditPage::class, ['record' => $page->getKey()])->fillForm(['title' => 'New prices'])->call('save');

        Livewire::actingAs($editor)
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertSee('Changes not on the website yet');

        Livewire::actingAs($editor)
            ->test(ListPages::class)
            ->assertSee('Changes waiting')
            ->set('activeTab', 'changes')
            ->assertCanSeeTableRecords([$page]);

        Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->assertSee('Saved, not published')
            ->callAction('publishChanges')
            ->assertNotified('Changes published');

        $this->assertSame('New prices', $page->fresh()->title);
        $this->assertFalse($page->fresh()->hasWorkingCopy());
    }

    public function test_ai_apps_edit_the_working_copy_and_publish_it(): void
    {
        $page = $this->livePage();
        $editor = $this->userWithRole('Editor');

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => $page->id, 'title' => 'AI prices'])->assertOk()->assertSee('"has_unpublished_changes":true');
        $this->assertSame('Prices', $page->fresh()->title);

        CmsServer::actingAs($editor)->tool(PublishPage::class, ['page' => $page->id, 'action' => 'publish'])->assertOk();
        $this->assertSame('AI prices', $page->fresh()->title);
        $this->assertFalse($page->fresh()->hasWorkingCopy());
    }
}
