<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class EmbedDropTest extends TestCase
{
    use RefreshDatabase;

    protected function page(array $data = []): Page
    {
        return Page::factory()->homepage()->create([
            'author_id' => $this->userWithRole('Editor')->id,
            'blocks' => [
                ['id' => 'embed', 'type' => 'embed', 'data' => $data],
                ['id' => 'text', 'type' => 'text', 'data' => ['body' => 'Hi']],
            ],
        ]);
    }

    public function test_the_canvas_embed_is_a_drop_zone_but_the_public_page_is_not(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->author)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->assertSeeHtml('data-fpb-dropzone')
            ->assertSee('Drop a link here');

        $this->get('/')->assertDontSee('data-fpb-dropzone', false);
    }

    public function test_dropping_a_link_sets_the_embed_and_can_be_undone(): void
    {
        $page = $this->page();

        $component = Livewire::actingAs($page->author)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('setEmbedUrl', 'embed', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertNotified('Embed updated')
            ->assertSet('selectedId', 'embed')
            ->assertSet('blockData.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $component->call('undo')->call('save');

        $this->assertArrayNotHasKey('url', $page->refresh()->blocks[0]['data']);
    }

    public function test_dropping_on_an_already_selected_embed_replaces_its_link(): void
    {
        $page = $this->page(['url' => 'https://vimeo.com/76979871']);

        Livewire::actingAs($page->author)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('selectBlock', 'embed')
            ->call('setEmbedUrl', 'embed', '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>')
            ->call('save');

        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $page->refresh()->applyWorkingCopy()->blocks[0]['data']['url']);
    }

    public function test_links_that_cannot_be_embedded_are_explained_not_saved(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->author)
            ->test(DesignPage::class, ['record' => $page->getKey()])
            ->call('setEmbedUrl', 'embed', 'https://evil.example.com/page')
            ->assertNotified('That link cannot be embedded')
            ->call('setEmbedUrl', 'text', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertSet('isDirty', false);
    }
}
