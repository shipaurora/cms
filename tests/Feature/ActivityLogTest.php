<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Activities\Pages\ListActivities;
use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\UpdatePage;
use Aurora\Models\Activity;
use Aurora\Models\Page;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_people_make_are_logged_with_who_and_what(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->actingAs($editor);

        $page = Page::factory()->create(['title' => 'Prices']);
        $page->update(['title' => 'New prices', 'excerpt' => 'Cheaper now.']);
        $page->delete();
        Setting::put(['page_cache' => ['enabled' => true]]);

        $log = Activity::query()->where('subject_id', $page->id)->orderBy('created_at')->get();

        $this->assertSame(['created', 'updated', 'deleted'], $log->pluck('action')->all());
        $this->assertTrue($log->every(fn (Activity $entry): bool => $entry->user->is($editor)));
        $this->assertEqualsCanonicalizing(['title', 'excerpt'], $log[1]->changes);
        $this->assertSame('New prices', $log[1]->subject_label);
        $this->assertTrue(Activity::query()->where('subject_label', 'page_cache')->exists());
    }

    public function test_ai_apps_changes_are_logged_as_the_person_they_act_for(): void
    {
        $editor = $this->userWithRole('Editor');
        $page = Page::factory()->create();

        CmsServer::actingAs($editor)->tool(UpdatePage::class, ['page' => $page->id, 'excerpt' => 'From the AI'])->assertOk();

        $this->assertTrue(Activity::query()->where('subject_id', $page->id)->where('action', 'updated')->sole()->user->is($editor));
    }

    public function test_what_the_system_does_by_itself_isn_t_logged(): void
    {
        Page::factory()->create()->reindex();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_person_s_own_preferences_aren_t_logged(): void
    {
        $editor = $this->userWithRole('Editor');
        $this->actingAs($editor);

        $editor->update(['sidebar_collapsed' => true, 'theme' => 'light', 'locale' => 'nb']);
        $this->assertFalse(Activity::query()->where('subject_id', $editor->id)->exists());

        // What they're called is another matter.
        $editor->update(['name' => 'Ingrid Editor', 'theme' => 'dark']);
        $this->assertSame(['name'], Activity::query()->where('subject_id', $editor->id)->sole()->changes);
    }

    public function test_only_people_allowed_see_the_log(): void
    {
        $this->actingAs($this->userWithRole('Editor'));
        Page::factory()->create(['title' => 'Logged page']);

        Livewire::test(ListActivities::class)->assertForbidden();

        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListActivities::class)
            ->assertOk()
            ->assertSee('Logged page');
    }
}
