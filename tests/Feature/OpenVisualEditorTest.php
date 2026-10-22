<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class OpenVisualEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_nothing_to_save_it_goes_straight_to_the_canvas(): void
    {
        $page = Page::factory()->create();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->callAction('design')
            ->assertRedirect(PageResource::getUrl('design', ['record' => $page]));
    }

    public function test_with_unsaved_changes_it_asks_to_save_them_first(): void
    {
        $page = Page::factory()->create(['title' => 'Before']);

        // Save and open: saved, then the canvas.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'After'])
            ->mountAction('design')
            ->assertActionMounted('design')
            ->callMountedAction()
            ->assertRedirect(PageResource::getUrl('design', ['record' => $page]));
        $this->assertSame('After', $page->fresh()->title);

        // Discard changes: the canvas, without them.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Not kept'])
            ->mountAction('design')
            ->callAction(TestAction::make('discardChanges'))
            ->assertRedirect(PageResource::getUrl('design', ['record' => $page]));
        $this->assertSame('After', $page->fresh()->title);
    }

    public function test_the_content_section_s_button_and_preview_ask_too(): void
    {
        $page = Page::factory()->create();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            // The preview's "Edit visually" goes through the same action.
            ->assertSeeHtml('wire:click.prevent="mountAction(\'design\')"')
            ->fillForm(['title' => 'Changed'])
            ->mountAction(TestAction::make('design')->schemaComponent('content', schema: 'form'))
            ->assertActionMounted(TestAction::make('design')->schemaComponent('content', schema: 'form'));
    }
}
