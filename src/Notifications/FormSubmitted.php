<?php

namespace Aurora\Notifications;

use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Models\FormSubmission;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class FormSubmitted extends Notification
{
    public function __construct(public FormSubmission $submission) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $form = $this->submission->form;
        $page = $this->submission->page?->title;

        $mail = (new MailMessage)
            ->subject(__('New :form submission', ['form' => $form->name]))
            ->greeting($form->name)
            ->line($page
                ? __('Someone filled in the form on “:page”:', ['page' => $page])
                : __('Someone filled in the form:'));

        foreach ($this->submission->answers() as $answer) {
            $mail->line(new HtmlString('**'.e($answer['label']).':** '.e($answer['value'] ?: '–')));
        }

        if ($this->submission->getMedia('files')->isNotEmpty()) {
            $mail->line(__('Attached files: :count (see the CMS).', ['count' => $this->submission->getMedia('files')->count()]));
        }

        return $mail
            ->action(__('Open in :cms', ['cms' => config('aurora.name')]), FormResource::getUrl('edit', ['record' => $form], panel: 'admin', tenant: $form->site));
    }
}
