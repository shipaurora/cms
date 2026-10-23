<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Pages\Pages\DesignPage;
use Aurora\Models\Page;
use Aurora\Support\InlineRichText;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class InlineRichTextTest extends TestCase
{
    use RefreshDatabase;

    protected const HTML = '<h2>Prices</h2><p>All <strong>plans</strong> include <a href="/support">support</a> and <a href="https://example.com" target="_blank">more</a>.</p><ul><li><p>Monthly</p></li></ul><blockquote><p>Worth it.</p></blockquote>';

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    protected function document(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    protected function paragraph(string $text, array $marks = []): array
    {
        return ['type' => 'paragraph', 'content' => [array_filter(['type' => 'text', 'text' => $text, 'marks' => $marks])]];
    }

    public function test_formatting_links_headings_lists_and_quotes_go_back_and_forth_unchanged(): void
    {
        $this->assertTrue(InlineRichText::isEditable(self::HTML));
        $this->assertSame(
            '<h2>Prices</h2><p>All <strong>plans</strong> include <a href="/support">support</a> and <a href="https://example.com" target="_blank" rel="noopener">more</a>.</p><ul><li><p>Monthly</p></li></ul><blockquote><p>Worth it.</p></blockquote>',
            InlineRichText::html(InlineRichText::document(self::HTML)),
        );
    }

    public function test_text_with_more_than_the_canvas_edits_stays_the_inspectors(): void
    {
        $this->assertFalse(InlineRichText::isEditable('<p>Plans</p><table><tr><td>Monthly</td></tr></table>'));
        $this->assertFalse(InlineRichText::isEditable('<p>Plans</p><img src="/plans.png">'));
        $this->assertFalse(InlineRichText::isEditable('<p style="text-align: center">Plans</p>'));
        $this->assertFalse(InlineRichText::isEditable('<pre><code>plans()</code></pre>'));
        $this->assertNull(InlineRichText::document('<p>Plans</p><hr>'));

        $this->assertTrue(InlineRichText::isEditable(null));
        $this->assertTrue(InlineRichText::isEditable(''));
    }

    public function test_only_what_the_canvas_writes_is_kept_from_a_document(): void
    {
        $html = InlineRichText::html($this->document([
            ['type' => 'image', 'attrs' => ['src' => '/plans.png']],
            $this->paragraph('<script>alert(1)</script>', [['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['color' => 'red']]]),
            $this->paragraph('Click', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)', 'class' => 'x']]]),
            ['type' => 'heading', 'attrs' => ['level' => 1, 'onclick' => 'alert(1)'], 'content' => [['type' => 'text', 'text' => 'Plans']]],
        ]));

        $this->assertSame('<p><strong>&lt;script&gt;alert(1)&lt;/script&gt;</strong></p><p>Click</p><h2>Plans</h2>', $html);

        $this->assertSame('', InlineRichText::html($this->document([['type' => 'paragraph']])));
        $this->assertNull(InlineRichText::html('<p>Not a document</p>'));
        $this->assertNull(InlineRichText::html(['type' => 'paragraph']));
    }

    public function test_rich_text_is_written_on_the_canvas_and_saved(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create(['author_id' => $editor->id, 'blocks' => [
            ['id' => 'text', 'type' => 'rich-text', 'data' => ['body' => '<p>Old</p>', 'width' => 'prose'], 'settings' => []],
            ['id' => 'table', 'type' => 'rich-text', 'data' => ['body' => '<p>Plans</p><table><tr><td>Monthly</td></tr></table>', 'width' => 'prose'], 'settings' => []],
        ]]);

        $this->actingAs($editor);

        $canvas = Livewire::test(DesignPage::class, ['record' => $page->getKey()])
            // The editable element, drawn for the canvas script.
            ->assertSeeHtml('data-fpb-kind="richText"');

        $this->assertSame(['editable' => true, 'document' => $this->document([$this->paragraph('Old')])], $canvas->instance()->richTextDocument('text', 'body'));
        $this->assertSame(['editable' => false, 'document' => null], $canvas->instance()->richTextDocument('table', 'body'));
        // Only fields declared Editable::richText().
        $this->assertFalse($canvas->instance()->richTextDocument('text', 'width')['editable']);

        $canvas
            ->call('setBlockField', 'text', 'body', $this->document([$this->paragraph('New', [['type' => 'italic']])]))
            // A string isn't a document: the package's plain text commit is ignored.
            ->call('setBlockField', 'text', 'body', 'flattened')
            // The table would be lost: never written from the canvas.
            ->call('setBlockField', 'table', 'body', $this->document([$this->paragraph('Plans')]))
            ->call('save');

        $page->refresh();
        $this->assertSame('<p><em>New</em></p>', $page->blocks[0]['data']['body']);
        $this->assertStringContainsString('<table>', $page->blocks[1]['data']['body']);
    }
}
