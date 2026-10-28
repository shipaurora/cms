<?php

namespace Aurora\Jobs;

use Aurora\Models\Asset;
use Aurora\Support\ImageTags;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The AI assistant tagging one image (Aurora\Support\ImageTags), on the queue so uploading
 * never waits for it. Once per image at a time, and tried again a little later when the AI
 * provider is busy. $asked when someone asked for it, with their instruction.
 */
class TagImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $assetId, public ?string $instruction = null, public bool $asked = false) {}

    public function uniqueId(): string
    {
        return $this->assetId;
    }

    public function handle(): void
    {
        if (($asset = Asset::query()->find($this->assetId)) !== null) {
            ImageTags::tag($asset, $this->instruction, $this->asked);
        }
    }
}
