<?php

namespace Aurora\Tests\Feature;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Pages\Auth\AcceptInvitation;
use Aurora\Filament\Resources\Forms\Pages\EditForm;
use Aurora\Filament\Resources\Forms\RelationManagers\SubmissionsRelationManager;
use Aurora\Filament\Resources\Pages\Pages\ListPages;
use Aurora\Models\Form;
use Aurora\Models\Invitation;
use Aurora\Models\Page;
use Aurora\Models\Role;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The title of each notification the person has, newest first.
     *
     * @return array<int, string>
     */
    protected function titles(User $user): array
    {
        return $user->notifications()->latest()->get()->pluck('data.title')->all();
    }

    public function test_a_page_sent_for_review_notifies_everyone_who_may_publish_it(): void
    {
        $author = $this->userWithRole('Author', ['name' => 'Aksel']);
        $editor = $this->userWithRole('Editor');
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $viewer = $this->userWithRole('Viewer');
        $page = Page::factory()->create(['title' => 'Pricing', 'author_id' => $author->id]);

        $this->actingAs($author);
        $page->update(['status' => PageStatus::Review]);

        $this->assertSame(['Aksel sent "Pricing" for review'], $this->titles($editor));
        $this->assertStringEndsWith('/pages/'.$page->getKey().'/edit', $editor->notifications()->sole()->data['actions'][0]['url'], 'Review opens the page, not the canvas.');
        $this->assertSame(['Aksel sent "Pricing" for review'], $this->titles($admin));
        $this->assertSame([], $this->titles($viewer));
        $this->assertSame([], $this->titles($author), 'Not to whoever sent it.');

        // Saving it again while in review doesn't notify again.
        $page->update(['title' => 'Prices']);
        $this->assertCount(1, $this->titles($editor));
    }

    public function test_only_people_working_on_that_site_hear_about_it(): void
    {
        $shop = Site::query()->create(['name' => 'Shop', 'handle' => 'shop']);
        $author = $this->userWithRole('Author');
        $author->sites()->attach($shop);
        $mainEditor = $this->userWithRole('Editor');
        $shopEditor = $this->userWithRole('Editor');
        $shopEditor->sites()->attach($shop);
        $page = Page::factory()->for($shop)->create(['author_id' => $author->id]);

        $this->actingAs($author);
        $page->update(['status' => PageStatus::Review]);

        $this->assertCount(1, $this->titles($shopEditor));
        $this->assertSame([], $this->titles($mainEditor));
    }

    public function test_the_author_hears_when_someone_else_publishes_their_page(): void
    {
        $author = $this->userWithRole('Author');
        $editor = $this->userWithRole('Editor', ['name' => 'Ingrid']);
        $mine = Page::factory()->create(['title' => 'Mine', 'author_id' => $editor->id]);
        $theirs = Page::factory()->create(['title' => 'About', 'status' => PageStatus::Review, 'author_id' => $author->id]);

        $this->actingAs($editor);
        $theirs->update(['status' => PageStatus::Published]);
        $mine->update(['status' => PageStatus::Published]);

        $this->assertSame(['Ingrid published "About"'], $this->titles($author));
        $this->assertSame([], $this->titles($editor));
    }

    public function test_a_form_submission_notifies_everyone_who_reads_submissions(): void
    {
        $form = Form::factory()->create(['name' => 'Contact']);
        $editor = $this->userWithRole('Editor');
        $author = $this->userWithRole('Author');

        $this->post(route('forms.submit', $form), [
            'name' => 'Kari',
            'email' => 'kari@example.com',
            'budget' => '€10–50k',
            'message' => 'Hello!',
            'consent' => '1',
            '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ])->assertRedirect();

        $this->assertSame(['New submission to "Contact"'], $this->titles($editor));
        $this->assertSame([], $this->titles($author));
    }

    public function test_notifications_are_written_in_each_persons_language(): void
    {
        $author = $this->userWithRole('Author', ['name' => 'Aksel']);
        $editor = $this->userWithRole('Editor', ['locale' => 'nb']);
        $page = Page::factory()->create(['title' => 'Pricing', 'author_id' => $author->id]);

        $this->actingAs($author);
        $page->update(['status' => PageStatus::Review]);

        $this->assertSame(['Aksel sendte «Pricing» til gjennomgang'], $this->titles($editor));
    }

    public function test_changes_nobody_made_notify_nobody(): void
    {
        $editor = $this->userWithRole('Editor');

        Page::factory()->create(['status' => PageStatus::Review]);

        $this->assertSame([], $this->titles($editor));
    }

    public function test_whoever_sent_an_invitation_hears_when_it_is_accepted(): void
    {
        $inviter = $this->userWithRole(User::SUPER_ADMIN);
        $invitation = Invitation::query()->create([
            'email' => 'invitee@example.com',
            'role_id' => Role::findByName('Author')->id,
            'invited_by_id' => $inviter->id,
            'expires_at' => now()->addDays(Invitation::VALID_FOR_DAYS),
        ]);

        Livewire::test(AcceptInvitation::class, ['invitation' => $invitation])
            ->fillForm(['name' => 'Nora New', 'password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1'])
            ->call('accept')
            ->assertHasNoFormErrors();

        $this->assertSame(['Nora New joined as Author'], $this->titles($inviter));
    }

    public function test_the_bell_lists_notifications_and_reading_them_clears_the_count(): void
    {
        $editor = $this->userWithRole('Editor');
        $editor->notify(Notification::make()->title('Something happened')->toDatabase());

        $bell = Livewire::actingAs($editor)->test(DatabaseNotifications::class)
            ->assertSee('Something happened')
            ->assertSeeHtml('cms-notifications-list');

        // What opening the dropdown calls.
        $bell->call('markAllNotificationsAsRead');

        $this->assertSame(0, $editor->unreadNotifications()->count());
    }

    public function test_publishing_a_page_withdraws_the_requests_to_review_it_and_redraws_the_sidebar(): void
    {
        $author = $this->userWithRole('Author');
        $editor = $this->userWithRole('Editor');
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $page = Page::factory()->create(['title' => 'Pricing', 'author_id' => $author->id]);
        $other = Page::factory()->create(['title' => 'Careers', 'author_id' => $author->id]);

        $this->actingAs($author);
        $page->update(['status' => PageStatus::Review]);
        $other->update(['status' => PageStatus::Review]);
        $this->assertCount(2, $this->titles($admin));

        Livewire::actingAs($editor)
            ->test(ListPages::class)
            ->callAction(TestAction::make('publishPage')->table($page))
            ->assertDispatched('refresh-sidebar');

        // Only the published page's request goes; the other is still waiting.
        $this->assertSame(['Author sent "Careers" for review'], str_replace($author->name, 'Author', $this->titles($admin)));
        $this->assertSame(['Author sent "Careers" for review'], str_replace($author->name, 'Author', $this->titles($editor)));
    }

    public function test_a_submission_notification_opens_that_submission(): void
    {
        $form = Form::factory()->create();
        $editor = $this->userWithRole('Editor');

        $this->post(route('forms.submit', $form), [
            'name' => 'Kari',
            'email' => 'kari@example.com',
            'message' => 'Hello!',
            'consent' => '1',
            '_started' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ])->assertRedirect();

        $url = $editor->notifications()->sole()->data['actions'][0]['url'];

        $this->assertStringContainsString('tableAction=view', $url);
        $this->assertStringContainsString('tableActionRecord='.$form->submissions()->sole()->getKey(), $url);
        $this->assertStringContainsString('actionComponent=submissions', $url);

        // Opening it (which "Open" does) reads it: the notification goes, for everyone.
        Livewire::actingAs($editor)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $form, 'pageClass' => EditForm::class])
            ->mountTableAction('view', $form->submissions()->sole());

        $this->assertSame(0, $editor->notifications()->count());
    }
}
