<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\BlockTreeBuilder;
use Aurora\Support\CustomShortcodes;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class CustomShortcodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortcodes_written_in_settings_are_used_on_the_website(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'website', 'section' => 'shortcodes'])
            ->test(SiteSettings::class)
            ->assertSee('Shortcodes')
            ->fillForm([CustomShortcodes::SETTING => [
                ['name' => 'phone', 'text' => "+47 55 00 00 00\n<b>weekdays</b>", 'description' => 'Our phone number'],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        Page::factory()->homepage()->published()->create(['blocks' => BlockTreeBuilder::flatten([
            ['type' => 'heading', 'data' => ['text' => 'Call [phone] today', 'level' => 'h2']],
        ])]);

        // As text, line breaks kept; never as HTML.
        $this->get('/')->assertOk()->assertSee("Call +47 55 00 00 00<br>\n&lt;b&gt;weekdays&lt;/b&gt; today", false);

        // A change shows straight away.
        Setting::put([CustomShortcodes::SETTING => [['name' => 'phone', 'text' => '+47 99 99 99 99']]]);
        $this->get('/')->assertSee('Call +47 99 99 99 99 today', false);
    }

    public function test_a_shortcode_can_t_take_a_built_in_name_or_another_s(): void
    {
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'website', 'section' => 'shortcodes'])
            ->test(SiteSettings::class)
            ->fillForm([CustomShortcodes::SETTING => [
                ['name' => 'year', 'text' => '1999'],
                ['name' => '1st', 'text' => 'x'],
            ]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertContains('year', CustomShortcodes::reserved());
        $this->assertSame([], CustomShortcodes::all());
    }
}
