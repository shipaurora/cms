<?php

namespace Aurora\Jobs;

use Aurora\Models\Asset;
use Aurora\Support\ImageDescriptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The AI assistant writing one image's alt text (Aurora\Support\ImageDescriptions), on the
 * queue so uploading never waits for it. Once per image at a time, and tried again a little
 * later when the AI provider is busy.
 */
class DescribeImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $assetId) {}

    public function uniqueId(): string
    {
        return $this->assetId;
    }

    public function handle(): void
    {
        if (($asset = Asset::query()->find($this->assetId)) !== null) {
            ImageDescriptions::describe($asset);
        }
    }
}
