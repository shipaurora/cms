<?php

namespace Aurora\Tests\Feature;

use Aurora\Tests\TestCase;
use Illuminate\Support\Facades\Blade;

class BlockViewHelpersTest extends TestCase
{
    public function test_block_views_can_use_the_short_helpers_instead_of_importing_classes(): void
    {
        $html = Blade::render(<<<'BLADE'
            <a href="{{ link_href($data) }}" target="{{ link_target($data) }}">{{ inline_text($data['title']) }}</a>
            <p>{{ plain_text($data['body']) }}</p>
            {{ rich_text($data['rich']) }}
            @if (shows_field('empty', null)) <i>empty</i> @endif
            @if (! is_editing()) <b>live</b> @endif
            {{ block_slot('content') }}
            BLADE, ['data' => [
            'link_type' => 'url',
            'link_url' => 'https://example.com',
            'link_new_tab' => true,
            'title' => 'The *best* CMS',
            'body' => '<script>x</script>',
            'rich' => '<p>Hello <script>alert(1)</script></p>',
        ]]);

        $this->assertStringContainsString('<a href="https://example.com" target="_blank">The <span class="text-gradient">best</span> CMS</a>', $html);
        $this->assertStringContainsString('<p>&lt;script&gt;x&lt;/script&gt;</p>', $html);
        $this->assertStringContainsString('<p>Hello', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('empty', $html);
        $this->assertStringContainsString('<b>live</b>', $html);
        $this->assertStringContainsString('<div class="fpb-slot"></div>', $html);
    }

    public function test_the_helpers_are_blade_directives_too(): void
    {
        $html = Blade::render(<<<'BLADE'
            <a href="@linkHref($data)" target="@linkTarget($data)">@inlineText($data['title'])</a>
            <p>@plainText($data['body'])</p>
            @richText($data['rich'])
            @shows('empty', null) <i>empty</i> @endshows
            @editing <b>canvas</b> @else <b>live</b> @endediting
            @blockSlot('content')
            BLADE, ['data' => [
            'link_type' => 'url',
            'link_url' => 'https://example.com',
            'link_new_tab' => true,
            'title' => 'The *best* CMS',
            'body' => '<script>x</script>',
            'rich' => '<p>Hello <script>alert(1)</script></p>',
        ]]);

        $this->assertStringContainsString('<a href="https://example.com" target="_blank">The <span class="text-gradient">best</span> CMS</a>', $html);
        $this->assertStringContainsString('<p>&lt;script&gt;x&lt;/script&gt;</p>', $html);
        $this->assertStringContainsString('<p>Hello', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('empty', $html);
        $this->assertStringContainsString('<b>live</b>', $html);
        $this->assertStringNotContainsString('canvas', $html);
        $this->assertStringContainsString('<div class="fpb-slot"></div>', $html);
    }
}
