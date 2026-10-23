<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\RevisionSource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Filament\Resources\Pages\Pages\EditPage;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Support\BlockCatalogue;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class VisualEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeater_items_can_be_edited_in_place_and_saved_as_a_canvas_revision(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'faq', 'type' => 'accordion', 'data' => ['items' => [['question' => 'Old?', 'answer' => 'Yes']]]],
        ]]);

        $this->actingAs($editor);

        // @editableItem marks each item's fields on the canvas, and nothing on the website.
        $this->get($page->previewUrl())->assertDontSee('data-fpb-field="items.0.question"', false);

        Livewire::test(DesignPage::class, ['record' => $page->getKey()])
            ->assertSeeHtml('data-fpb-field="items.0.question"')
            ->assertSeeHtml('data-fpb-field="items.0.answer"')
            ->call('setBlockField', 'faq', 'items.0.question', 'New question?')
            ->call('setBlockField', 'faq', 'items.0.secret', 'nope')
            ->call('setBlockField', 'faq', 'items.5.question', 'nope')
            ->call('save');

        $page->refresh();

        $this->assertSame('New question?', $page->blocks[0]['data']['items'][0]['question']);
        $this->assertArrayNotHasKey('secret', $page->blocks[0]['data']['items'][0]);
        $this->assertCount(1, $page->blocks[0]['data']['items']);
        $this->assertSame(RevisionSource::Canvas, $page->revisions()->first()->source);
    }

    public function test_exit_returns_to_the_pages_own_edit_screen(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id]);

        $design = Livewire::actingAs($editor)->test(DesignPage::class, ['record' => $page->getKey()])->instance();

        $this->assertSame('Exit', $design->exitLabel());
        $this->assertSame(PageResource::getUrl('edit', ['record' => $page]), $design->exitUrl());
    }

    public function test_an_author_cannot_open_someone_elses_page_in_the_editor(): void
    {
        $page = Page::factory()->create(['author_id' => $this->userWithRole('Editor')->id]);

        $this->actingAs($this->userWithRole('Author'))
            ->get(PageResource::getUrl('design', ['record' => $page]))
            ->assertForbidden();
    }

    public function test_every_block_can_be_inserted_selected_and_saved(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $page = Page::factory()->create(['author_id' => $admin->id, 'blocks' => []]);

        $this->actingAs($admin);

        $component = Livewire::test(DesignPage::class, ['record' => $page->getKey()]);

        foreach (array_keys(BlockCatalogue::all()) as $type) {
            $component->set('selectedId', null)->call('insertBlock', $type);
            $id = $component->get('selectedId');

            $this->assertNotNull($id, "Could not insert a {$type} block.");
            $component->call('selectBlock', $id)->assertOk();
        }

        $component->call('save')->assertHasNoErrors();

        $this->assertCount(count(BlockCatalogue::all()), $page->refresh()->blocks);
        $this->get($page->previewUrl())->assertOk();
    }

    /**
     * Inspector bindings on block data that only reach the server on the next request:
     * a plain wire:model, or an Alpine-bound field with no live modifier.
     *
     * @return array<int, string>
     */
    protected function deferredBindings(string $html): array
    {
        preg_match_all('/wire:model="blockData\.[^"]+"|stateBindingModifiers: JSON\.parse\(&#039;\[\]&#039;\),\s*state: \$wire\.\$entangle\(&#039;blockData\.[a-z_]+/', $html, $matches);

        return $matches[0];
    }

    public function test_every_inspector_field_updates_the_canvas_when_it_changes(): void
    {
        $admin = $this->userWithRole('Super Admin');
        $page = Page::factory()->create(['author_id' => $admin->id, 'blocks' => []]);
        $component = Livewire::actingAs($admin)->test(DesignPage::class, ['record' => $page->getKey()]);
        $checked = 0;

        foreach (array_keys(BlockCatalogue::all()) as $type) {
            $component->set('selectedId', null)->call('insertBlock', $type);
            $html = $component->html();

            $this->assertSame([], $this->deferredBindings($html), "The {$type} block has fields that do not update the canvas until the next request.");
            $checked += substr_count($html, '$entangle(&#039;blockData.') + substr_count($html, 'wire:model.live="blockData.') + substr_count($html, 'wire:model.live.blur="blockData.');
        }

        $this->assertGreaterThan(50, $checked, 'The inspector fields were actually rendered and checked.');
    }

    public function test_switching_a_form_from_card_to_plain_redraws_the_canvas(): void
    {
        $editor = $this->userWithRole('Editor');
        $form = Form::factory()->create();
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'form', 'type' => 'form', 'data' => ['form_id' => $form->id, 'style' => 'card']],
        ]]);

        $component = Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('selectBlock', 'form')
            ->assertSeeHtml('stateBindingModifiers: JSON.parse(&#039;[\u0022live\u0022]&#039;),')
            ->assertSeeHtml('shadow-xl shadow-zinc-900/5');

        $component->set('blockData.style', 'plain')->assertDontSeeHtml('shadow-xl shadow-zinc-900/5');
    }

    public function test_a_blocks_page_link_is_picked_in_a_dialog_and_removed_from_its_card(): void
    {
        $editor = $this->userWithRole('Editor');
        $pricing = Page::factory()->create(['title' => 'Pricing', 'slug' => 'pricing']);
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'card', 'type' => 'card', 'data' => ['title' => 'Card', 'link_type' => 'page']],
        ]]);

        $design = Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('selectBlock', 'card')
            ->assertSee('Choose page')
            ->callAction(TestAction::make('pickPage')->schemaComponent('link_page', schema: 'form'), data: ['page' => $pricing->id])
            ->assertHasNoActionErrors()
            ->assertSee('/pricing')
            ->call('save');

        $this->assertSame($pricing->id, $page->refresh()->blocks[0]['data']['link_page']);

        $design->call('callSchemaComponentMethod', 'form.link_page', 'clearPage')->call('save');

        $this->assertNull($page->refresh()->blocks[0]['data']['link_page']);
    }

    public function test_the_canvas_redraws_from_what_the_assistant_saved(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'heading', 'type' => 'heading', 'data' => ['text' => 'Join the crew', 'level' => 'h2']],
        ]]);

        $canvas = Livewire::actingAs($editor)->test(DesignPage::class, ['record' => $page->getKey()]);

        $page->update(['blocks' => [
            ['id' => 'heading', 'type' => 'heading', 'data' => ['text' => 'Work with us', 'level' => 'h2']],
        ]]);

        $canvas->call('refreshFromAssistant')
            ->assertSee('Work with us')
            ->assertDontSee('Join the crew')
            ->assertSet('isDirty', false);

        $this->assertSame($page->fresh()->updated_at->toJSON(), $canvas->get('loadedUpdatedAt'));
    }

    public function test_unsaved_canvas_edits_are_not_replaced_by_the_assistant(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'heading', 'type' => 'heading', 'data' => ['text' => 'Join the crew', 'level' => 'h2']],
        ]]);

        $canvas = Livewire::actingAs($editor)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('setBlockField', 'heading', 'text', 'My draft');

        $page->update(['blocks' => [
            ['id' => 'heading', 'type' => 'heading', 'data' => ['text' => 'Work with us', 'level' => 'h2']],
        ]]);

        $canvas->call('refreshFromAssistant');

        $this->assertSame('My draft', $canvas->get('blocks')[0]['data']['text']);
    }

    public function test_a_page_form_redraws_from_what_the_assistant_saved(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'title' => 'Careers']);

        $form = Livewire::actingAs($editor)->test(EditPage::class, ['record' => $page->getRouteKey()]);

        $page->update(['title' => 'Jobs']);

        $form->call('refreshFromAssistant')->assertSchemaStateSet(['title' => 'Jobs'], 'form');

        $form->set('data.title', 'My draft');
        $page->update(['title' => 'Open roles']);

        $form->call('refreshFromAssistant')->assertSchemaStateSet(['title' => 'My draft'], 'form');
    }
}
