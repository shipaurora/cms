<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Filament\Resources\Pages\RelationManagers\RevisionsRelationManager;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class PageRevisionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_pages_snapshot_noindex_as_false(): void
    {
        $page = Page::factory()->create();

        $this->assertFalse($page->toSnapshot()['noindex']);
    }

    public function test_an_older_revision_with_a_null_noindex_can_be_restored(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['title' => 'Careers now', 'noindex' => true]);
        $revision = $page->revisions()->create([
            'user_id' => $editor->id,
            'source' => 'editor',
            'summary' => 'Before',
            'snapshot' => ['title' => 'Careers', 'slug' => $page->slug, 'blocks' => [], 'layout_id' => '01900000-0000-7000-8000-000000000000', 'noindex' => null, 'unknown' => 'ignored'],
        ]);

        Livewire::actingAs($editor)
            ->test(RevisionsRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditPage::class])
            ->callTableAction('restore', $revision)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Version restored');

        $page->refresh();

        $this->assertSame('Careers', $page->title);
        $this->assertFalse($page->noindex);
        // A layout deleted since: the default.
        $this->assertNull($page->layout_id);
        $this->assertTrue($page->layoutOrDefault()->is_default);
    }

    public function test_saving_reloads_the_history(): void
    {
        $page = Page::factory()->create();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'A new title'])
            ->call('save')
            ->assertDispatched('page-saved');

        // The history listens for it, and shows the new version.
        Livewire::test(RevisionsRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditPage::class])
            ->dispatch('page-saved')
            ->assertSee('A new title');
    }
}
