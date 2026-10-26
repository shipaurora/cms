<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Jobs\DescribeImage;
use Aurora\Models\Asset;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\AiWriter;
use Aurora\Support\AssetLibrary;
use Aurora\Support\Copilot;
use Aurora\Support\ImageDescriptions;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

class ImageDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function switchOnTheAssistant(bool $describeImages = true): void
    {
        Setting::put(['copilot' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'describe_images' => $describeImages]]);
        Copilot::storeApiKey('sk-ant-test-1234');
    }

    protected function image(?string $alt = null): Asset
    {
        return AssetLibrary::fromUpload(UploadedFile::fake()->image('ridge.jpg', 400, 300), ['alt' => $alt]);
    }

    public function test_a_new_image_without_alt_text_is_described_only_when_switched_on(): void
    {
        Queue::fake();

        $this->image();
        $this->switchOnTheAssistant(describeImages: false);
        $this->image();
        Queue::assertNothingPushed();

        $this->switchOnTheAssistant();
        $described = $this->image();
        $this->image('Written by hand');
        AssetLibrary::fromUpload(UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%%EOF"));

        Queue::assertPushed(DescribeImage::class, 1);
        Queue::assertPushed(DescribeImage::class, fn (DescribeImage $job): bool => $job->assetId === $described->id);
    }

    public function test_the_assistant_writes_the_alt_text_from_the_image_but_never_replaces_one(): void
    {
        Queue::fake();
        $this->switchOnTheAssistant();
        AiWriter::fake(['A green ridge in morning mist above a fjord.']);
        $image = $this->image();
        $written = $this->image('Written by hand');

        (new DescribeImage($image->id))->handle();
        (new DescribeImage($written->id))->handle();

        $this->assertSame('A green ridge in morning mist above a fjord.', $image->fresh()->alt);
        $this->assertSame('Written by hand', $written->fresh()->alt);
        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1 && $prompt->contains('Alt text'));
    }

    public function test_switching_it_on_describes_the_images_already_without_alt_text(): void
    {
        $this->switchOnTheAssistant(describeImages: false);
        $missing = $this->image();
        $this->image('Written by hand');
        Queue::fake();
        $this->actingAs($this->userWithRole(User::SUPER_ADMIN));

        Livewire::withQueryParams(['tab' => 'ai'])
            ->test(SiteSettings::class)
            ->fillForm([ImageDescriptions::SETTING => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Describing 1 image');

        $this->assertTrue(ImageDescriptions::isOn());
        Queue::assertPushed(DescribeImage::class, 1);
        Queue::assertPushed(DescribeImage::class, fn (DescribeImage $job): bool => $job->assetId === $missing->id);

        // Saved again while on: nothing more to send.
        Livewire::withQueryParams(['tab' => 'ai'])->test(SiteSettings::class)->call('save');
        Queue::assertPushed(DescribeImage::class, 1);
    }

    public function test_selected_images_without_alt_text_can_be_described_in_bulk_with_an_instruction(): void
    {
        // Asked for by hand: the automatic setting doesn't need to be on.
        $this->switchOnTheAssistant(describeImages: false);
        $missing = $this->image();
        $written = $this->image('Written by hand');
        $pdf = AssetLibrary::fromUpload(UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%%EOF"));
        Queue::fake();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertTableBulkActionVisible('writeAltText')
            ->callTableBulkAction('writeAltText', [$missing, $written, $pdf], ['instruction' => 'From the spring workshop in Bergen'])
            ->assertNotified('Writing alt text for 1 image');

        Queue::assertPushed(DescribeImage::class, 1);
        Queue::assertPushed(DescribeImage::class, fn (DescribeImage $job): bool => $job->assetId === $missing->id && $job->asked);

        AiWriter::fake(['Workshop tables under a spring sky.']);
        Queue::pushedJobs()[DescribeImage::class][0]['job']->handle();

        $this->assertSame('Workshop tables under a spring sky.', $missing->fresh()->alt);
        AiWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('From the spring workshop in Bergen'));
    }

    public function test_bulk_alt_text_is_offered_only_with_the_assistant_set_up(): void
    {
        $this->image();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(ListAssets::class)
            ->assertTableBulkActionHidden('writeAltText');
    }
}
