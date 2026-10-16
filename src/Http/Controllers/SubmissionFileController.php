<?php

namespace Aurora\Http\Controllers;

use Aurora\Models\FormSubmission;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file someone sent through a form (a CV, a photo): kept on the private disk and only
 * handed to signed-in people who may see form submissions, through a signed, expiring link
 * from the admin. Always as a download, never shown in the browser.
 */
class SubmissionFileController
{
    public function __invoke(Media $media): StreamedResponse
    {
        abort_unless($media->model_type === (new FormSubmission)->getMorphClass() && $media->collection_name === 'files', 404);
        Gate::authorize('view-any Form');

        return $media->toResponse(request());
    }
}
