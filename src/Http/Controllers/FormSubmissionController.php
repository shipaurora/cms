<?php

namespace Aurora\Http\Controllers;

use Aurora\Enums\SubmissionStatus;
use Aurora\Events\FormSubmitted as FormSubmittedEvent;
use Aurora\Models\Form;
use Aurora\Models\FormSubmission;
use Aurora\Models\Page;
use Aurora\Notifications\FormSubmitted;
use Aurora\Support\CmsNotifications;
use Aurora\Support\ContentLocale;
use Aurora\Support\FormFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Receives a form posted from a "Form" block on any page.
 *
 * A plain HTML POST: it works without JavaScript, and errors and old input come back
 * to the page through the session. Spam is caught by a honeypot field and a minimum fill
 * time (bots submit instantly); the route is also rate limited.
 */
class FormSubmissionController
{
    public const MINIMUM_SECONDS = 2;

    public function __invoke(Request $request, Form $form): RedirectResponse
    {
        abort_unless($form->is_active, 404);

        // In the language the form was shown in: its labels, choices and messages.
        ContentLocale::set(is_string($locale = $request->input('_locale')) ? $locale : null);
        app()->setLocale(ContentLocale::current());

        // Only the fields that were showing, for the answers given.
        $fields = FormFields::visible($form->definitions(), $request->all());
        $bag = 'form_'.$form->id;
        $anchor = '#form-'.$form->id;

        $validated = validator(
            $request->all(),
            collect($fields)->mapWithKeys(fn (array $field): array => [$field['name'] => FormFields::rules($field)])->all(),
            [],
            collect($fields)->mapWithKeys(fn (array $field): array => [$field['name'] => strtolower($field['label'])])->all(),
        );

        if ($validated->fails()) {
            return redirect()->to(url()->previous().$anchor)->withErrors($validated, $bag)->withInput();
        }

        $looksLikeSpam = filled($request->input('website')) || ! $this->filledSlowlyEnough($request);

        $submission = FormSubmission::query()->create([
            'form_id' => $form->id,
            'page_id' => is_string($page = $request->input('_page')) ? Page::query()->find($page)?->id : null,
            'status' => $looksLikeSpam ? SubmissionStatus::Spam : SubmissionStatus::New,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'data' => collect($fields)
                ->reject(fn (array $field): bool => $field['type'] === 'file')
                ->mapWithKeys(fn (array $field): array => [$field['name'] => [
                    'label' => $field['label'],
                    'value' => $field['type'] === 'consent' ? ($request->boolean($field['name']) ? 'Yes' : 'No') : $request->input($field['name']),
                ]])
                ->all(),
        ]);

        foreach ($fields as $field) {
            if ($field['type'] === 'file' && ($file = $request->file($field['name'])) instanceof UploadedFile) {
                $submission->addMedia($file)->withCustomProperties(['field' => $field['name'], 'label' => $field['label']])->toMediaCollection('files');
            }
        }

        // The people who read submissions hear about it in the admin; spam stays quiet.
        if (! $looksLikeSpam) {
            CmsNotifications::formSubmitted($submission);
            FormSubmittedEvent::dispatch($submission);
        }

        if (! $looksLikeSpam && filled($form->notify)) {
            // The submission is saved either way: a mail server that's down is logged, not shown.
            rescue(fn () => Notification::route('mail', $form->notify)->notify(new FormSubmitted($submission)));
        }

        if ($form->redirectPage) {
            return redirect()->to($form->redirectPage->url());
        }

        return redirect()->to(url()->previous().$anchor)->with($bag.'.sent', true);
    }

    protected function filledSlowlyEnough(Request $request): bool
    {
        try {
            return now()->timestamp - (int) Crypt::decryptString((string) $request->input('_started')) >= self::MINIMUM_SECONDS;
        } catch (Throwable) {
            return false;
        }
    }
}
