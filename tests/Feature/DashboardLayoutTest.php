<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Pages\Dashboard;
use Aurora\Filament\Widgets\ContentStats;
use Aurora\Filament\Widgets\NeedsAttention;
use Aurora\Filament\Widgets\RecentChanges;
use Aurora\Filament\Widgets\WelcomeWidget;
use Aurora\Support\DashboardLayout;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_layout_of_their_own_people_get_the_default_dashboard(): void
    {
        $this->actingAs($editor = $this->userWithRole('Editor'));

        $this->assertSame([
            ['widget' => WelcomeWidget::class, 'size' => 'large'],
            ['widget' => ContentStats::class, 'size' => 'large'],
            ['widget' => RecentChanges::class, 'size' => 'medium'],
            ['widget' => NeedsAttention::class, 'size' => 'small'],
        ], DashboardLayout::for($editor));
    }

    public function test_a_person_reorders_resizes_and_hides_widgets_and_saves_their_own_dashboard(): void
    {
        $editor = $this->userWithRole('Editor');
        $colleague = $this->userWithRole('Editor');

        Livewire::actingAs($editor)
            ->test(Dashboard::class)
            ->callAction('customize')
            ->assertSet('customizing', true)
            ->assertSee('Drag the widgets into the order you want')
            ->call('reorderWidgets', [NeedsAttention::class, WelcomeWidget::class, RecentChanges::class, ContentStats::class])
            ->call('resizeWidget', NeedsAttention::class, 'large')
            ->call('resizeWidget', WelcomeWidget::class, 'huge')
            ->call('hideWidget', ContentStats::class)
            ->assertSee('Content in numbers')
            ->callAction('saveDashboard')
            ->assertSet('customizing', false)
            ->assertNotified('Dashboard saved');

        $this->assertSame([
            ['widget' => NeedsAttention::class, 'size' => 'large'],
            ['widget' => WelcomeWidget::class, 'size' => 'large'],
            ['widget' => RecentChanges::class, 'size' => 'medium'],
        ], DashboardLayout::for($editor->fresh()));
        $this->assertSame([ContentStats::class], DashboardLayout::hidden($editor->fresh()));

        // Theirs only.
        $this->assertCount(4, DashboardLayout::for($colleague));
    }

    public function test_cancelling_keeps_the_saved_dashboard_and_a_hidden_widget_can_be_added_back(): void
    {
        $editor = $this->userWithRole('Editor');
        DashboardLayout::save($editor, [['widget' => WelcomeWidget::class, 'size' => 'small']]);

        Livewire::actingAs($editor->fresh())
            ->test(Dashboard::class)
            ->callAction('customize')
            ->call('hideWidget', WelcomeWidget::class)
            ->callAction('cancelCustomizing')
            ->assertSet('customizing', false);

        $this->assertSame([['widget' => WelcomeWidget::class, 'size' => 'small']], DashboardLayout::for($editor->fresh()));

        Livewire::actingAs($editor->fresh())
            ->test(Dashboard::class)
            ->callAction('customize')
            ->call('showWidget', RecentChanges::class)
            ->callAction('saveDashboard');

        $this->assertSame(
            [['widget' => WelcomeWidget::class, 'size' => 'small'], ['widget' => RecentChanges::class, 'size' => 'medium']],
            DashboardLayout::for($editor->fresh()),
        );
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

        $this->assertSame(DashboardLayout::default(), DashboardLayout::for($editor->fresh()));
    }

    public function test_a_saved_layout_never_shows_widgets_the_person_may_not_see_or_that_no_longer_exist(): void
    {
        $editor = $this->userWithRole('Editor');
        $editor->forceFill(['dashboard' => [
            'widgets' => [['widget' => 'App\Filament\Widgets\Gone', 'size' => 'small'], ['widget' => RecentChanges::class, 'size' => 'tiny']],
            'hidden' => [WelcomeWidget::class],
        ]])->save();

        $this->actingAs($editor);

        // An unknown size falls back to the widget's own; widgets in neither list are new and come last.
        $this->assertSame([
            ['widget' => RecentChanges::class, 'size' => 'medium'],
            ['widget' => ContentStats::class, 'size' => 'large'],
            ['widget' => NeedsAttention::class, 'size' => 'small'],
        ], DashboardLayout::for($editor));
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
