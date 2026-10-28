<?php

namespace Aurora\Filament\Widgets;

use Aurora\Filament\Pages\AiAssistant;
use Aurora\Filament\Resources\McpActivities\McpActivityResource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\McpActivity;
use Aurora\Models\Page;
use Aurora\Support\CurrentSite;
use Filament\Widgets\Widget;

class WelcomeWidget extends Widget
{
    /**
     * Its name and what it shows, when customising the dashboard (DashboardLayout).
     */
    public static function getDashboardLabel(): string
    {
        return __('Welcome');
    }

    public static function getDashboardDescription(): string
    {
        return __('A greeting with shortcuts to what you do most, and your AI apps.');
    }

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'aurora::filament.widgets.welcome';

    /**
     * Until an AI app is connected: how to connect one. Once connected: what it has
     * been doing, from the AI activity log.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    protected function aiAction(): ?array
    {
        if (! AiAssistant::canAccess()) {
            return null;
        }

        $connections = AiAssistant::currentConnections();

        if ($connections->isEmpty()) {
            return [__('Connect your AI'), __('Let Claude edit with your permissions'), 'phosphor-sparkle', AiAssistant::getUrl()];
        }

        $lastAction = McpActivity::query()->where('user_id', auth()->id())->latest()->first();
        $description = $lastAction
            ? __('Last action :when', ['when' => $lastAction->created_at->diffForHumans()])
            : __(':name is connected', ['name' => $connections->first()['name']]);

        return McpActivityResource::canViewAny()
            ? [__('AI activity'), $description, 'phosphor-terminal-window', McpActivityResource::getUrl()]
            : [__('Your AI is connected'), $description, 'phosphor-seal-check', AiAssistant::getUrl()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $home = Page::query()->homepage()->first();
        $user = auth()->user();

        return [
            'user' => $user,
            'greeting' => match (true) {
                now()->hour < 12 => __('Good morning'),
                now()->hour < 18 => __('Good afternoon'),
                default => __('Good evening'),
            },
            'actions' => array_values(array_filter([
                $user->can('create', Page::class) ? [__('New page'), __('Start blank or from a template'), 'phosphor-file-plus', PageResource::getUrl('index', ['action' => 'create'])] : null,
                $home && $user->can('update', $home) ? [__('Edit the homepage'), __('Open it in the visual editor'), 'phosphor-paint-brush', PageResource::getUrl('design', ['record' => $home])] : null,
                $this->aiAction(),
                [__('View the website'), __('See it as visitors do'), 'phosphor-globe', CurrentSite::url('/')],
            ])),
        ];
    }
}
