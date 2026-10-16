<?php

namespace Aurora\Notifications;

use Aurora\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class InvitedToCms extends Notification
{
    public function __construct(public Invitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'inviter' => $this->invitation->inviter?->name ?? __('Someone'),
            'cms' => config('aurora.name'),
            'role' => $this->invitation->role->displayName(),
        ];

        $mail = (new MailMessage)
            ->subject(__(':inviter invited you to :cms', $replace))
            ->greeting(__('Hi!'))
            ->line(__(':inviter invited you to join :cms as **:role**.', $replace));

        if (filled($this->invitation->message)) {
            // A quote: escaped here, as a line made of HTML isn't escaped again.
            $mail->line(new HtmlString('> '.str_replace("\n", "\n> ", e($this->invitation->message))));
        }

        return $mail
            ->action(__('Accept the invitation'), $this->invitation->url())
            ->line(__('You will choose your name and password. The link works once and expires :date.', ['date' => $this->invitation->expires_at->translatedFormat('j F')]))
            ->line(__('Not expecting this? You can safely ignore the email.'));
    }
}
