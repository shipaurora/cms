<?php

namespace Aurora\Filament\Pages;

use Aurora\Filament\Concerns\TranslatesPageLabels;
use Aurora\Support\DashboardLayout;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The dashboard, laid out by each person: "Customise dashboard" turns the widgets into cards to
 * drag into another order, make small, medium or large, hide or add back (DashboardLayout).
 * Nothing changes until they save; "Back to the default" starts again from the panel's layout.
 */
class Dashboard extends BaseDashboard
{
    use TranslatesPageLabels;

    /**
     * Whether the dashboard is being laid out, instead of showing its widgets.
     */
    public bool $customizing = false;

    /**
     * The layout being made: the widgets shown, in order, with their size.
     *
     * @var array<int, array{widget: string, size: string}>
     */
    public array $draft = [];

    /**
     * No page heading: the welcome card at the top already says where you are.
     * The browser tab keeps the "Dashboard" title.
     */
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }

    public function getWidgetsContentComponent(): Component
    {
        return View::make('aurora::filament.pages.dashboard')
            ->viewData(fn (): array => [
                'customizing' => $this->customizing,
                'layout' => $this->customizing ? $this->draft : DashboardLayout::for(auth()->user()),
                'hidden' => $this->customizing ? $this->hiddenInDraft() : [],
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customize')
                ->label(__('Customise dashboard'))
                ->icon('phosphor-squares-four')
                ->color('gray')
                ->hidden(fn (): bool => $this->customizing)
                ->action(function (): void {
                    $this->draft = DashboardLayout::for(auth()->user());
                    $this->customizing = true;
                }),
            Action::make('resetDashboard')
                ->label(__('Back to the default'))
                ->icon('phosphor-arrow-counter-clockwise')
                ->color('gray')
                ->link()
                ->visible(fn (): bool => $this->customizing)
                ->action(fn () => $this->draft = DashboardLayout::default()),
            Action::make('cancelCustomizing')
                ->label(__('Cancel'))
                ->color('gray')
                ->visible(fn (): bool => $this->customizing)
                ->action(function (): void {
                    $this->customizing = false;
                    $this->draft = [];
                }),
            Action::make('saveDashboard')
                ->label(__('Save dashboard'))
                ->visible(fn (): bool => $this->customizing)
                ->action(function (): void {
                    DashboardLayout::save(auth()->user(), $this->draft);
                    $this->customizing = false;
                    $this->draft = [];

                    Notification::make()->title(__('Dashboard saved'))->success()->send();
                }),
        ];
    }

    /**
     * Put the widgets in the order they were dragged into.
     *
     * @param  array<int, string>  $order
     */
    public function reorderWidgets(array $order): void
    {
        $sizes = array_column($this->draft, 'size', 'widget');

        $this->draft = collect($order)
            ->filter(fn (mixed $widget): bool => is_string($widget) && array_key_exists($widget, $sizes))
            ->unique()
            ->map(fn (string $widget): array => ['widget' => $widget, 'size' => $sizes[$widget]])
            // Any the browser left out keep their place at the end.
            ->concat(collect($this->draft)->reject(fn (array $item): bool => in_array($item['widget'], $order, true)))
            ->values()
            ->all();
    }

    public function resizeWidget(string $widget, string $size): void
    {
        if (! array_key_exists($size, DashboardLayout::SIZES)) {
            return;
        }

        $this->draft = array_map(fn (array $item): array => $item['widget'] === $widget ? [...$item, 'size' => $size] : $item, $this->draft);
    }

    public function hideWidget(string $widget): void
    {
        $this->draft = array_values(array_filter($this->draft, fn (array $item): bool => $item['widget'] !== $widget));
    }

    public function showWidget(string $widget): void
    {
        if (in_array($widget, $this->hiddenInDraft(), true)) {
            $this->draft[] = ['widget' => $widget, 'size' => DashboardLayout::defaultSize($widget)];
        }
    }

    /**
     * Widgets the person may see that the layout being made leaves out.
     *
     * @return array<int, string>
     */
    protected function hiddenInDraft(): array
    {
        return array_values(array_diff(DashboardLayout::available(), array_column($this->draft, 'widget')));
    }
}
