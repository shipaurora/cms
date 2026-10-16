<?php

namespace Aurora\Database\Seeders\Demo;

use Aurora\Enums\PageStatus;
use Aurora\Enums\SubmissionStatus;
use Aurora\Models\FormSubmission;
use Aurora\Models\Page;
use Aurora\Support\CmsNotifications;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * The notifications the demo's story would have sent: each page waiting for review (from
     * its author) and each unread form submission. Delivered right away rather than queued,
     * so a fresh demo has them without a queue worker running.
     */
    public function run(): void
    {
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $this->send();
        } finally {
            config(['queue.default' => $queue]);
        }
    }

    protected function send(): void
    {
        Page::query()->where('status', PageStatus::Review)->with('author')->get()
            ->filter(fn (Page $page): bool => $page->author !== null)
            ->each(fn (Page $page) => CmsNotifications::pageSentForReview($page, $page->author));

        FormSubmission::query()->where('status', SubmissionStatus::New)->with('form')->oldest()->get()
            ->each(fn (FormSubmission $submission) => CmsNotifications::formSubmitted($submission));
    }
}
