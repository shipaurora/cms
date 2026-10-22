<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class ConfirmsLeavingTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_with_unsaved_changes_saves_them_first_when_asked(): void
    {
        $page = Page::factory()->create(['title' => 'Before']);
        $to = PageResource::getUrl('index');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->assertSet('confirmsLeaving', true)
            ->fillForm(['title' => 'After'])
            ->callAction(TestAction::make('leavePage')->arguments(['url' => $to]))
            ->assertRedirect($to);

        $this->assertSame('After', $page->fresh()->title);
    }

    public function test_discarding_leaves_without_saving(): void
    {
        $page = Page::factory()->create(['title' => 'Before']);
        $to = PageResource::getUrl('index');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Not kept'])
            ->mountAction(TestAction::make('leavePage')->arguments(['url' => $to]))
            ->callAction(TestAction::make('discardAndLeave'))
            ->assertRedirect($to);

        $this->assertSame('Before', $page->fresh()->title);
    }

    public function test_a_form_that_can_t_be_saved_stays_and_links_elsewhere_go_to_the_dashboard(): void
    {
        $page = Page::factory()->create(['title' => 'Before']);

        // A required field emptied: not saved, so not left.
        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => ''])
            ->callAction(TestAction::make('leavePage')->arguments(['url' => PageResource::getUrl('index')]))
            ->assertNoRedirect();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['title' => 'Changed'])
            ->mountAction(TestAction::make('leavePage')->arguments(['url' => 'https://elsewhere.example/phish']))
            ->callAction(TestAction::make('discardAndLeave'))
            ->assertRedirect(filament()->getUrl());
    }

    public function test_a_new_record_is_created_before_leaving(): void
    {
        $to = PageResource::getUrl('index');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(CreatePage::class)
            ->fillForm(['title' => 'Newsletter'])
            ->callAction(TestAction::make('leavePage')->arguments(['url' => $to]))
            ->assertRedirect($to);

        $this->assertTrue(Page::query()->whereSlug('newsletter')->exists());
    }
}
