<?php

namespace Aurora\Support;

use Aurora\Models\Activity;
use Aurora\Models\ApiKey;
use Aurora\Models\Asset;
use Aurora\Models\AssetFolder;
use Aurora\Models\Form;
use Aurora\Models\ImageFormat;
use Aurora\Models\Layout;
use Aurora\Models\Locale;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\NavigationLocation;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Aurora\Models\Role;
use Aurora\Models\Setting;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Models\Webhook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Who changed what across the CMS (Administration › Activity log): every change people make,
 * in the admin or through an AI app, to the content and to how the CMS is set up. What
 * the system does by itself (a reindex, a scheduled page going live) isn't someone's change.
 */
class ActivityLog
{
    /**
     * What's logged, and its name in the log.
     *
     * @var array<class-string<Model>, string>
     */
    public const MODELS = [
        Page::class => 'Page',
        Menu::class => 'Menu',
        MenuItem::class => 'Menu item',
        Form::class => 'Form',
        Redirect::class => 'Redirect',
        Asset::class => 'File',
        AssetFolder::class => 'Folder',
        User::class => 'User',
        Role::class => 'Role',
        Layout::class => 'Layout',
        NavigationLocation::class => 'Menu location',
        Site::class => 'Site',
        Locale::class => 'Language',
        Setting::class => 'Setting',
        Webhook::class => 'Webhook',
        ApiKey::class => 'API key',
        ImageFormat::class => 'Image format',
    ];

    /**
     * Changes not worth a line: bookkeeping the CMS does itself.
     *
     * @var array<int, string>
     */
    protected const IGNORED = ['updated_at', 'created_at', 'search_text', 'indexed_at', 'last_edited_by_id', 'last_seen_at', 'last_signed_in_at', 'remember_token', 'last_status', 'last_error', 'last_sent_at', 'working_copy_at', 'working_copy_by_id'];

    public static function listen(): void
    {
        foreach (array_keys(self::MODELS) as $model) {
            foreach (['created', 'updated', 'deleted', 'restored'] as $action) {
                Event::listen("eloquent.{$action}: {$model}", fn (Model $subject) => static::record($subject, $action));
            }
        }
    }

    public static function record(Model $subject, string $action): void
    {
        if (auth()->guest()) {
            return;
        }

        $changes = $action === 'updated'
            ? array_values(array_diff(array_keys($subject->getChanges()), self::IGNORED))
            : null;

        if ($action === 'updated' && $changes === []) {
            return;
        }

        Activity::query()->create([
            'user_id' => auth()->id(),
            'site_id' => $subject->getAttribute('site_id') ?? CurrentSite::id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'subject_label' => static::label($subject),
            'changes' => $changes,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * What a thing is called, for the log.
     */
    public static function label(Model $subject): ?string
    {
        foreach (['title', 'name', 'label', 'url', 'key', 'from_path', 'handle', 'code'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 250);
            }
        }

        return null;
    }

    public static function typeName(string $morphClass): string
    {
        return __(self::MODELS[$morphClass] ?? class_basename($morphClass));
    }
}
