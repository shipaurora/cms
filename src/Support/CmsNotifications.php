<?php

namespace Aurora\Support;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Forms\RelationManagers\SubmissionsRelationManager;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Models\FormSubmission;
use Aurora\Models\Invitation;
use Aurora\Models\Locale;
use Aurora\Models\Page;
use Aurora\Models\Site;
use Aurora\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * The admin's notifications (the bell in the topbar): who hears about what.
 *
 * Each goes to the people who can act on it, on the site it happened on, never to whoever
 * caused it, in each recipient's own admin language. Delivered through the queue (Filament's
 * database notifications are queued), so a worker must be running.
 */
class CmsNotifications
{
    /**
     * A page was sent for review: to everyone who may publish it.
     */
    public static function pageSentForReview(Page $page, User $by): void
    {
        $recipients = static::recipients($page->site, $by, fn (User $user): bool => $user->can('publish', $page));

        static::send($recipients, fn (string $locale): Notification => Notification::make()
            ->title(__(':name sent ":page" for review', ['name' => $by->name, 'page' => $page->title], $locale))
            ->body(__('It is waiting for someone to publish it.', [], $locale))
            ->icon(PageStatus::Review->getIcon())
            ->iconColor('warning')
            // Tagged with the page, so the request can be withdrawn (reviewDone()).
            ->viewData(['cms_subject' => static::reviewSubject($page)])
            ->actions([
                Action::make('review')->label(__('Review', [], $locale))->button()->url(PageResource::settingsUrl($page))->markAsRead(),
            ]));
    }

    /**
     * The page is no longer waiting for review (published, or back to a draft): the requests
     * to review it are out of date, so they go.
     */
    public static function reviewDone(Page $page): void
    {
        static::withdraw(static::reviewSubject($page));
    }

    protected static function reviewSubject(Page $page): string
    {
        return 'page-review:'.$page->getKey();
    }

    /**
     * Someone opened the submission (or marked it read, archived or spam): it is no longer
     * new, so the notifications about it go, for everyone.
     */
    public static function submissionHandled(FormSubmission $submission): void
    {
        static::withdraw(static::submissionSubject($submission));
    }

    protected static function submissionSubject(FormSubmission $submission): string
    {
        return 'submission:'.$submission->getKey();
    }

    /**
     * Deletes the notifications about one thing (tagged with ->viewData(['cms_subject' => …])).
     */
    protected static function withdraw(string $subject): void
    {
        DatabaseNotification::query()->where('data->viewData->cms_subject', $subject)->delete();
    }

    /**
     * Someone else published a page: to its author.
     */
    public static function pagePublished(Page $page, User $by): void
    {
        $author = $page->author;

        if ($author === null) {
            return;
        }

        $recipients = static::recipients($page->site, $by, fn (User $user): bool => $user->is($author));

        static::send($recipients, fn (string $locale): Notification => Notification::make()
            ->title(__(':name published ":page"', ['name' => $by->name, 'page' => $page->title], $locale))
            ->body(__('It is live on the website now.', [], $locale))
            ->icon('phosphor-broadcast')
            ->iconColor('success')
            ->actions([
                Action::make('view')->label(__('View page', [], $locale))->button()->url($page->url(), shouldOpenInNewTab: true)->markAsRead(),
            ]));
    }

    /**
     * Someone filled in a form on the website: to everyone who reads its submissions.
     */
    public static function formSubmitted(FormSubmission $submission): void
    {
        $form = $submission->form;
        $recipients = static::recipients($form->site, null, fn (User $user): bool => $user->can('view', $form));

        static::send($recipients, fn (string $locale): Notification => Notification::make()
            ->title(__('New submission to ":form"', ['form' => $form->name], $locale))
            ->body($submission->summary() ?: null)
            ->icon('phosphor-tray')
            ->iconColor('info')
            ->viewData(['cms_subject' => static::submissionSubject($submission)])
            ->actions([
                Action::make('open')
                    ->label(__('Open', [], $locale))
                    ->button()
                    ->url(SubmissionsRelationManager::submissionUrl($submission))
                    ->markAsRead(),
            ]));
    }

    /**
     * Someone accepted an invitation: to whoever invited them.
     */
    public static function invitationAccepted(Invitation $invitation, User $user): void
    {
        $inviter = $invitation->inviter;

        if ($inviter === null || $inviter->is_active === false) {
            return;
        }

        static::send(collect([$inviter]), fn (string $locale): Notification => Notification::make()
            ->title(__(':name joined as :role', ['name' => $user->name, 'role' => static::inLocale($locale, fn (): string => $invitation->role->displayName())], $locale))
            ->body(__('They accepted your invitation.', [], $locale))
            ->icon('phosphor-user-plus')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label(__('View', [], $locale))
                    ->button()
                    ->url(UserResource::getUrl('edit', ['record' => $user], panel: 'admin', tenant: $inviter->accessibleSites()->first()))
                    ->markAsRead(),
            ]));
    }

    /**
     * Active people who may work on the site and pass the check, except whoever caused it.
     *
     * @param  Closure(User): bool  $may
     * @return Collection<int, User>
     */
    protected static function recipients(?Site $site, ?User $except, Closure $may): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles')
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get()
            ->filter(fn (User $user): bool => ($site === null || $user->canAccessTenant($site)) && $may($user))
            ->values();
    }

    /**
     * One notification per person, written in their admin language.
     *
     * @param  Collection<int, User>  $recipients
     * @param  Closure(string $locale): Notification  $make
     */
    protected static function send(Collection $recipients, Closure $make): void
    {
        foreach ($recipients as $recipient) {
            $locale = $recipient->locale ?: Locale::defaultCode();

            $recipient->notify($make($locale)->toDatabase());
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected static function inLocale(string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
