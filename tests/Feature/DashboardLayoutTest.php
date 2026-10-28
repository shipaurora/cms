<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\Dashboard;
use Aurora\Filament\Widgets\ContentStats;
use Aurora\Filament\Widgets\NeedsAttention;
use Aurora\Filament\Widgets\RecentChanges;
use Aurora\Filament\Widgets\WelcomeWidget;
use Aurora\Models\User;
use Aurora\Support\AdminPanel;
use Aurora\Support\DashboardLayout;
use Aurora\Tests\TestCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Workbench\App\Filament\Widgets\FormsOverview;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The widgets and sizes of a layout, without the ids.
     *
     * @param  array<int, array{id: string, widget: string, size: string}>  $layout
     * @return array<int, array{0: string, 1: string}>
     */
    protected function shape(array $layout): array
    {
        return array_map(fn (array $item): array => [$item['widget'], $item['size']], $layout);
    }

    public function test_without_a_layout_of_their_own_people_get_the_default_dashboard(): void
    {
        $this->actingAs($editor = $this->userWithRole('Editor'));

        $this->assertSame([
            ['id' => 'welcome-widget', 'widget' => WelcomeWidget::class, 'size' => 'large'],
            ['id' => 'content-stats', 'widget' => ContentStats::class, 'size' => 'large'],
            // The website's own (AdminPanel::widgets(), in the workbench's provider), placed by its
            // $sort (none: 0) and as wide as its $columnSpan (a stats widget's is full).
            ['id' => 'forms-overview', 'widget' => FormsOverview::class, 'size' => 'large'],
            ['id' => 'recent-changes', 'widget' => RecentChanges::class, 'size' => 'medium'],
            ['id' => 'needs-attention', 'widget' => NeedsAttention::class, 'size' => 'small'],
        ], DashboardLayout::for($editor));
    }

    public function test_a_person_reorders_resizes_removes_and_adds_widgets_and_saves_their_own_dashboard(): void
    {
        $editor = $this->userWithRole('Editor');
        $colleague = $this->userWithRole('Editor');

        $page = Livewire::actingAs($editor)
            ->test(Dashboard::class)
            ->callAction('customize')
            ->assertSet('customizing', true)
            ->assertSee('Add a widget')
            ->call('reorderWidgets', ['needs-attention', 'welcome-widget', 'recent-changes', 'content-stats'])
            ->call('removeWidget', 'forms-overview')
            ->call('resizeWidget', 'needs-attention', 'large')
            ->call('resizeWidget', 'welcome-widget', 'huge')
            ->call('removeWidget', 'content-stats')
            // The same widget twice.
            ->call('addWidget', RecentChanges::class)
            ->call('addWidget', 'App\Filament\Widgets\Unknown');

        $added = $page->get('draft')[3]['id'];
        $page->call('resizeWidget', $added, 'small')
            ->callAction('saveDashboard')
            ->assertSet('customizing', false)
            ->assertNotified('Dashboard saved');

        $layout = DashboardLayout::for($editor->fresh());
        $this->assertSame([
            [NeedsAttention::class, 'large'],
            [WelcomeWidget::class, 'large'],
            [RecentChanges::class, 'medium'],
            [RecentChanges::class, 'small'],
        ], $this->shape($layout));
        $this->assertCount(4, array_unique(array_column($layout, 'id')));

        // Theirs only.
        $this->assertCount(5, DashboardLayout::for($colleague));
    }

    public function test_the_layout_is_kept_with_a_websites_own_user_model(): void
    {
        $editor = SiteUser::query()->findOrFail($this->userWithRole('Editor')->getKey());

        DashboardLayout::save($editor, [['widget' => RecentChanges::class, 'size' => 'small']]);

        $this->assertSame([[RecentChanges::class, 'small']], $this->shape(DashboardLayout::for(SiteUser::query()->findOrFail($editor->getKey()))));
        $this->assertTrue($editor->isFillable('admin_theme'));
    }

    public function test_cancelling_keeps_the_saved_dashboard(): void
    {
        $editor = $this->userWithRole('Editor');
        DashboardLayout::save($editor, [['widget' => WelcomeWidget::class, 'size' => 'small']]);
        $id = DashboardLayout::for($editor->fresh())[0]['id'];

        Livewire::actingAs($editor->fresh())
            ->test(Dashboard::class)
            ->callAction('customize')
            ->call('removeWidget', $id)
            ->callAction('cancelCustomizing')
            ->assertSet('customizing', false);

        $this->assertSame([[WelcomeWidget::class, 'small']], $this->shape(DashboardLayout::for($editor->fresh())));
    }

    public function test_back_to_the_default_starts_again_from_the_panels_layout(): void
    {
        $editor = $this->userWithRole('Editor');
        DashboardLayout::save($editor, [['widget' => WelcomeWidget::class, 'size' => 'small']]);

        Livewire::actingAs($editor->fresh())
            ->test(Dashboard::class)
            ->callAction('customize')
            ->callAction('resetDashboard')
            ->callAction('saveDashboard');

        $this->assertSame($this->shape(DashboardLayout::default()), $this->shape(DashboardLayout::for($editor->fresh())));
    }

    public function test_a_removed_widget_stays_removed_but_a_new_one_appears_and_unknown_ones_never_show(): void
    {
        $editor = $this->userWithRole('Editor');
        $editor->forceFill(['dashboard' => [
            'widgets' => [['id' => 'gone', 'widget' => 'App\Filament\Widgets\Gone', 'size' => 'small'], ['id' => 'mine', 'widget' => RecentChanges::class, 'size' => 'tiny']],
            // NeedsAttention is new since they saved; WelcomeWidget and ContentStats they removed.
            'known' => [WelcomeWidget::class, ContentStats::class, RecentChanges::class, FormsOverview::class],
        ]])->save();

        $this->actingAs($editor);

        $this->assertSame([
            [RecentChanges::class, 'medium'],
            [NeedsAttention::class, 'small'],
        ], $this->shape(DashboardLayout::for($editor)));
    }

    public function test_a_layout_saved_by_1_3_8_still_reads(): void
    {
        $editor = $this->userWithRole('Editor');
        $editor->forceFill(['dashboard' => [
            'widgets' => [['widget' => RecentChanges::class, 'size' => 'large']],
            'hidden' => [WelcomeWidget::class, ContentStats::class, FormsOverview::class],
        ]])->save();

        $this->actingAs($editor);

        $this->assertSame([
            [RecentChanges::class, 'large'],
            [NeedsAttention::class, 'small'],
        ], $this->shape(DashboardLayout::for($editor)));
    }

    public function test_a_website_registers_its_own_widgets_and_people_can_add_them(): void
    {
        $this->assertContains(FormsOverview::class, AdminPanel::registeredWidgets());
        $this->assertSame([FormsOverview::class], AdminPanel::discover(dirname((new \ReflectionClass(FormsOverview::class))->getFileName()), 'Workbench\App\Filament\Widgets'));
        $editor = $this->userWithRole('Editor');
        DashboardLayout::save($editor, [['widget' => FormsOverview::class, 'size' => 'large']]);

        // Named after its class, as it has no getDashboardLabel().
        $this->assertSame('Forms Overview', DashboardLayout::label(FormsOverview::class));

        Livewire::actingAs($editor->fresh())
            ->test(Dashboard::class)
            ->callAction('customize')
            ->assertSee('Forms Overview')
            ->call('addWidget', FormsOverview::class)
            ->callAction('saveDashboard');

        $this->assertSame([[FormsOverview::class, 'large'], [FormsOverview::class, 'large']], $this->shape(DashboardLayout::for($editor->fresh())));

        // Drawn on the dashboard, and refreshed by Livewire as one of the panel's components.
        $this->actingAs($editor->fresh())->get(Dashboard::getUrl())->assertOk()->assertSee('Forms');
        Livewire::actingAs($editor->fresh())->test(FormsOverview::class)->assertOk();
    }

    public function test_the_dashboard_shows_the_widgets_in_the_persons_order(): void
    {
        $editor = $this->userWithRole('Editor');
        DashboardLayout::save($editor, [
            ['widget' => RecentChanges::class, 'size' => 'large'],
            ['widget' => WelcomeWidget::class, 'size' => 'small'],
        ]);

        $this->actingAs($editor->fresh())
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['data-size="large"', 'data-size="small"'], false)
            ->assertSee('Customise dashboard');
    }
}

/**
 * A website's own User model, as the starter app has it: its own casts and #[Fillable],
 * which replace Aurora's.
 */
#[Fillable(['name', 'email', 'locale', 'theme', 'password', 'avatar', 'job_title', 'is_active'])]
class SiteUser extends User
{
    protected $table = 'users';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
