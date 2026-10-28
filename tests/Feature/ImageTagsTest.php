<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Jobs\TagImage;
use Aurora\Models\Asset;
use Aurora\Models\AssetTag;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\AiWriter;
use Aurora\Support\AssetLibrary;
use Aurora\Support\Copilot;
use Aurora\Support\ImageTags;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

class ImageTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function switchOnTheAssistant(bool $tagImages = true): void
    {
        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'tag_images' => $tagImages]]);
        Copilot::storeApiKey('sk-ant-test-1234');
    }

    /**
     * @param  array<int, string>  $tags
     */
    protected function image(array $tags = []): Asset
    {
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('ridge.jpg', 400, 300));
        $asset->tags()->sync(collect($tags)->map(fn (string $name): string => AssetTag::query()->firstOrCreate(['name' => $name])->id)->all());

        return $asset;
    }

    public function test_a_new_image_is_tagged_only_when_switched_on(): void
    {
        Queue::fake();

        $this->image();
        $this->switchOnTheAssistant(tagImages: false);
        $this->image();
        Queue::assertNotPushed(TagImage::class);

        $this->switchOnTheAssistant();
        $tagged = $this->image();
        AssetLibrary::fromUpload(UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%%EOF"));

        Queue::assertPushed(TagImage::class, 1);
        Queue::assertPushed(TagImage::class, fn (TagImage $job): bool => $job->assetId === $tagged->id);
    }

    public function test_the_assistant_reuses_the_librarys_tags_and_adds_new_ones_only_when_none_fit(): void
    {
        Queue::fake();
        $this->switchOnTheAssistant();
        $landscape = AssetTag::query()->create(['name' => 'Landscape']);
        AiWriter::fake(['landscape, Fjord, Landscape, mountains']);
        $image = $this->image();

        (new TagImage($image->id))->handle();

        $this->assertSame(['Fjord', 'Landscape', 'mountains'], $image->tags()->pluck('name')->all());
        $this->assertSame(3, AssetTag::query()->count());
        $this->assertTrue($image->tags()->whereKey($landscape->id)->exists());
        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1 && $prompt->contains('Existing tags: Landscape'));
    }

    public function test_tags_someone_chose_are_never_changed(): void
    {
        Queue::fake();
        $this->switchOnTheAssistant();
        AiWriter::fake(['Fjord']);
        $image = $this->image(['Chosen by hand']);

        (new TagImage($image->id, asked: true))->handle();

        $this->assertSame(['Chosen by hand'], $image->tags()->pluck('name')->all());
        AiWriter::assertNeverPrompted();
    }

    public function test_the_answer_becomes_at_most_five_short_tags(): void
    {
        $this->assertSame(['One', 'two', 'Three', 'Four', 'Five'], ImageTags::names("One, two, Two\n- Three, Four, Five, Six"));
        $this->assertSame(ImageTags::MAX_LENGTH, mb_strlen(ImageTags::names(str_repeat('a', 60))[0]));
    }

    public function test_switching_it_on_tags_the_images_already_without_tags(): void
    {
        $this->switchOnTheAssistant(tagImages: false);
        $missing = $this->image();
        $this->image(['Chosen by hand']);
        Queue::fake();
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->fillForm([ImageTags::SETTING => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Tagging 1 image');

        $this->assertTrue(ImageTags::isOn());
        Queue::assertPushed(TagImage::class, 1);
        Queue::assertPushed(TagImage::class, fn (TagImage $job): bool => $job->assetId === $missing->id);
    }

    public function test_selected_images_without_tags_can_be_tagged_in_bulk_with_an_instruction(): void
    {
        // Asked for by hand: the automatic setting doesn't need to be on.
        $this->switchOnTheAssistant(tagImages: false);
        $missing = $this->image();
        $tagged = $this->image(['Chosen by hand']);
        Queue::fake();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertTableBulkActionVisible('tagImages')
            ->callTableBulkAction('tagImages', [$missing, $tagged], ['instruction' => 'Tag the season'])
            ->assertNotified('Tagging 1 image');

        Queue::assertPushed(TagImage::class, 1);
        Queue::assertPushed(TagImage::class, fn (TagImage $job): bool => $job->assetId === $missing->id && $job->asked);

        AiWriter::fake(['Spring']);
        Queue::pushedJobs()[TagImage::class][0]['job']->handle();

        $this->assertSame(['Spring'], $missing->tags()->pluck('name')->all());
        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Tag the season'));
    }

    public function test_bulk_tagging_is_offered_only_with_the_assistant_set_up(): void
    {
        $this->image();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertTableBulkActionHidden('tagImages');
    }
}
