<?php

namespace Aurora\Filament\Widgets;

use Aurora\Enums\PageStatus;
use Aurora\Enums\RevisionSource;
use Aurora\Models\McpActivity;
use Aurora\Models\Page;
use Aurora\Models\PageRevision;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ContentStats extends StatsOverviewWidget
{
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        // This site's pages' edits (revisions follow their page's site).
        $revisions = fn (): Builder => PageRevision::query()->whereHas('page', fn (Builder $query) => $query->withTrashed());
        $editsPerDay = static::perDay($revisions());
        $aiPerDay = static::perDay(McpActivity::query());

        return [
            Stat::make(__('Live pages'), Page::query()->live()->count())
                ->description(trans_choice(':count page in total|:count pages in total', Page::query()->count()))
                ->descriptionIcon('phosphor-broadcast')
                ->color('success'),
            Stat::make(__('Waiting for review'), Page::query()->where('status', PageStatus::Review)->count())
                ->description(__(':count scheduled to go live', ['count' => Page::query()->where('status', PageStatus::Published)->where('published_at', '>', now())->count()]))
                ->descriptionIcon('phosphor-clock')
                ->color('warning'),
            Stat::make(__('Edits, last 14 days'), array_sum($editsPerDay))
                ->description(__(':count in the visual editor', ['count' => $revisions()->where('source', RevisionSource::Canvas)->where('created_at', '>', now()->subDays(14))->count()]))
                ->descriptionIcon('phosphor-paint-brush')
                ->chart($editsPerDay)
                ->color('primary'),
            Stat::make(__('AI actions, last 14 days'), array_sum($aiPerDay))
                ->description(trans_choice(':count person uses an assistant|:count people use an assistant', McpActivity::query()->distinct('user_id')->count('user_id')))
                ->descriptionIcon('phosphor-sparkle')
                ->chart($aiPerDay)
                ->color('violet'),
        ];
    }

    /**
     * Rows per day over the last 14 days, oldest first: one query.
     *
     * @return array<int, int>
     */
    protected static function perDay(Builder $query): array
    {
        $start = now()->subDays(13)->startOfDay();
        $counts = $query->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(13, 0))->map(fn (int $daysAgo): int => (int) ($counts[now()->subDays($daysAgo)->toDateString()] ?? 0))->all();
    }
}
