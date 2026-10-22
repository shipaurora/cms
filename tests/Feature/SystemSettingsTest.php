<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Asset;
use Aurora\Models\Menu;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\WordPressImport;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected string $export;

    protected function setUp(): void
    {
        parent::setUp();

        $this->export = sys_get_temp_dir().'/aurora-wordpress-'.uniqid().'.xml';
        file_put_contents($this->export, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>Old site</title>
    <item>
        <title>Fjord</title>
        <wp:post_id>9</wp:post_id>
        <wp:post_type>attachment</wp:post_type>
        <wp:status>inherit</wp:status>
        <wp:attachment_url>https://old.example.com/wp-content/uploads/fjord.jpg</wp:attachment_url>
    </item>
    <item>
        <title>About us</title>
        <content:encoded><![CDATA[<!-- wp:paragraph --><p>We are a small team.</p><!-- /wp:paragraph --><img src="https://old.example.com/wp-content/uploads/fjord-300x200.jpg">]]></content:encoded>
        <excerpt:encoded><![CDATA[Who we are.]]></excerpt:encoded>
        <wp:post_id>2</wp:post_id>
        <wp:post_date>2024-05-01 10:00:00</wp:post_date>
        <wp:post_name>about</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_parent>0</wp:post_parent>
        <wp:post_type>page</wp:post_type>
    </item>
    <item>
        <title>Team</title>
        <content:encoded><![CDATA[Ingrid and Ola.]]></content:encoded>
        <wp:post_id>3</wp:post_id>
        <wp:post_name>team</wp:post_name>
        <wp:status>draft</wp:status>
        <wp:post_parent>2</wp:post_parent>
        <wp:post_type>page</wp:post_type>
    </item>
    <item>
        <title>Hello world</title>
        <content:encoded><![CDATA[<p>Our first post.</p>]]></content:encoded>
        <wp:post_id>4</wp:post_id>
        <wp:post_name>hello-world</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>post</wp:post_type>
    </item>
    <item>
        <title></title>
        <wp:post_id>20</wp:post_id>
        <wp:menu_order>1</wp:menu_order>
        <wp:post_type>nav_menu_item</wp:post_type>
        <category domain="nav_menu" nicename="main"><![CDATA[Main menu]]></category>
        <wp:postmeta><wp:meta_key>_menu_item_type</wp:meta_key><wp:meta_value>post_type</wp:meta_value></wp:postmeta>
        <wp:postmeta><wp:meta_key>_menu_item_object_id</wp:meta_key><wp:meta_value>2</wp:meta_value></wp:postmeta>
        <wp:postmeta><wp:meta_key>_menu_item_menu_item_parent</wp:meta_key><wp:meta_value>0</wp:meta_value></wp:postmeta>
    </item>
</channel>
</rss>
XML);
    }

    protected function tearDown(): void
    {
        @unlink($this->export);

        parent::tearDown();
    }

    public function test_a_wordpress_site_comes_in_with_its_pages_posts_images_and_menus(): void
    {
        Storage::fake('public');
        Http::fake(['old.example.com/*' => Http::response(UploadedFile::fake()->image('fjord.jpg', 40, 30)->getContent(), 200, ['Content-Type' => 'image/jpeg'])]);

        $result = (new WordPressImport)->import($this->export);

        $this->assertSame(['pages' => 2, 'posts' => 1, 'images' => 1, 'menus' => 1, 'skipped' => []], $result);

        $about = Page::query()->whereSlug('about')->sole();
        $this->assertTrue($about->isLive());
        $this->assertSame('Who we are.', $about->excerpt);
        $body = $about->blocks[0]['data']['body'];
        $this->assertStringContainsString('<p>We are a small team.</p>', $body);
        $this->assertStringNotContainsString('wp:paragraph', $body);
        $this->assertStringNotContainsString('old.example.com', $body, 'Its images point at the media library.');

        $team = Page::query()->whereSlug('team')->sole();
        $this->assertTrue($team->parent->is($about));
        $this->assertFalse($team->isLive());
        $this->assertSame('<p>Ingrid and Ola.</p>', $team->blocks[0]['data']['body']);

        $this->assertSame('Blog', Page::query()->whereSlug('hello-world')->sole()->parent->title);
        $this->assertSame(1, Asset::query()->count());
        $this->assertTrue(Menu::query()->where('name', 'Main menu')->sole()->menuItems->first()->linkable->is($about));
    }

    public function test_it_is_imported_from_settings_and_refuses_other_files(): void
    {
        Http::fake();
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'system', 'section' => 'import'])
            ->test(SiteSettings::class)
            ->assertActionHidden('save')
            ->callAction(TestAction::make('importWordPress')->schemaComponent('section.import.import-actions', schema: 'form'), data: [
                'file' => UploadedFile::fake()->createWithContent('export.xml', file_get_contents($this->export)),
                'posts' => false,
                'media' => false,
            ])
            ->assertNotified('Imported from WordPress');

        $this->assertSame(2, Page::query()->count());
    }

    public function test_maintenance_sends_a_test_email_and_clears_caches(): void
    {
        Mail::fake();
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $this->actingAs($admin);

        $system = Livewire::withQueryParams(['tab' => 'system'])->test(SiteSettings::class);
        $system->callAction(TestAction::make('sendTestEmail')->schemaComponent('section.maintenance.maintenance-actions', schema: 'form'))->assertNotified('Sent to '.$admin->email);
        $system->callAction(TestAction::make('clearCaches')->schemaComponent('section.maintenance.maintenance-actions', schema: 'form'))->assertNotified('Caches cleared');

        Livewire::withQueryParams(['tab' => 'system', 'section' => 'system-info'])->test(SiteSettings::class)->assertSee(['Laravel', 'PHP', app()->version()]);
    }
}
