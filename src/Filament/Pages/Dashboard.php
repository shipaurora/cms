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
 * drag into another order, make small, medium or large, or remove, and adds any widget, as often
 * as they like (DashboardLayout).
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
     * The layout being made: the widgets on it, in order, each with its id and size.
     *
     * @var array<int, array{id: string, widget: string, size: string}>
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
                'catalogue' => $this->customizing ? DashboardLayout::available() : [],
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
     * Put the widgets in the order they were dragged into, by their ids.
     *
     * @param  array<int, string>  $order
     */
    public function reorderWidgets(array $order): void
    {
        $byId = array_column($this->draft, null, 'id');

        $this->draft = collect($order)
            ->filter(fn (mixed $id): bool => is_string($id) && isset($byId[$id]))
            ->unique()
            ->map(fn (string $id): array => $byId[$id])
            // Any the browser left out keep their place at the end.
            ->concat(collect($this->draft)->reject(fn (array $item): bool => in_array($item['id'], $order, true)))
            ->values()
            ->all();
    }

    public function resizeWidget(string $id, string $size): void
    {
        if (! array_key_exists($size, DashboardLayout::SIZES)) {
            return;
        }

        $this->draft = array_map(fn (array $item): array => $item['id'] === $id ? [...$item, 'size' => $size] : $item, $this->draft);
    }

    public function removeWidget(string $id): void
    {
        $this->draft = array_values(array_filter($this->draft, fn (array $item): bool => $item['id'] !== $id));
    }

    /**
     * Add a widget at the end, also one that's already there: each gets an id of its own.
     */
    public function addWidget(string $widget): void
    {
        if (in_array($widget, DashboardLayout::available(), true)) {
            $this->draft[] = DashboardLayout::item($widget);
        }
    }
}
