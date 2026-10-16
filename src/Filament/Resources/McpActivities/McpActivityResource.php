<?php

namespace Aurora\Filament\Resources\McpActivities;

use Aurora\Enums\AiChannel;
use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\McpActivities\Pages\ListMcpActivities;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\McpActivity;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\PeoplePicker;
use Aurora\Support\UserBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * An audit trail of everything AI assistants did, and on whose behalf.
 */
class McpActivityResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = McpActivity::class;

    /**
     * Shared by every site: not tied to the site picked in the admin.
     */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-terminal-window';

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $modelLabel = 'AI action';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('update-any Page');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('user.name')->label(__('On behalf of'))->html()->formatStateUsing(fn (McpActivity $record): ?HtmlString => UserBadge::html($record->user)),
                TextEntry::make('tool')->badge()->fontFamily(FontFamily::Mono),
                TextEntry::make('created_at')->label(__('When'))->dateTime(),
            ]),
            TextEntry::make('summary')->placeholder(__('No summary')),
            ViewEntry::make('arguments')
                ->label(__('Arguments sent by the assistant'))
                ->view('aurora::filament.infolists.json-code')
                ->columnSpanFull(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'subject']))
            ->poll('10s')
            ->columns([
                IconColumn::make('succeeded')->label('')->boolean()->trueIcon('phosphor-check-circle-bold')->falseIcon('phosphor-x-circle-bold'),
                TextColumn::make('summary')
                    ->placeholder(fn (McpActivity $record): string => $record->tool)
                    ->description(fn (McpActivity $record): ?string => $record->subject instanceof Page ? $record->subject->title : null)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('channel')->label(__('Via'))->badge()->formatStateUsing(fn (AiChannel $state): string => $state === AiChannel::Assistant ? __('Assistant') : __('External app'))->color(fn (AiChannel $state): string => $state === AiChannel::Assistant ? 'primary' : 'gray'),
                TextColumn::make('tool')->badge()->color('gray')->fontFamily(FontFamily::Mono),
                TextColumn::make('user.name')->label(__('On behalf of'))->html()->formatStateUsing(fn (McpActivity $record): ?HtmlString => UserBadge::html($record->user)),
                TextColumn::make('created_at')->label(__('When'))->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->label(__('Via'))->options([AiChannel::Assistant->value => AiChannel::Assistant->label(), AiChannel::Mcp->value => AiChannel::Mcp->label()]),
                SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->label(__('Person'))
                    ->getOptionLabelFromRecordUsing(fn (User $user): string => PeoplePicker::option($user))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->allowHtml())
                    ->indicateUsing(fn (array $state): ?string => filled($state['value'] ?? null) ? __('Person').': '.User::query()->whereKey($state['value'])->value('name') : null),
                SelectFilter::make('tool')->options(fn (): array => McpActivity::query()->distinct()->orderBy('tool')->pluck('tool', 'tool')->all()),
                TernaryFilter::make('succeeded')->label(__('Outcome'))->trueLabel(__('Succeeded'))->falseLabel(__('Failed')),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
                Action::make('page')
                    ->label(__('Open page'))
                    ->icon('phosphor-paint-brush')
                    ->visible(fn (McpActivity $record): bool => $record->subject instanceof Page)
                    ->url(fn (McpActivity $record): ?string => $record->subject instanceof Page ? PageResource::editorUrl($record->subject) : null),
            ])
            ->emptyStateIcon('phosphor-sparkle')
            ->emptyStateHeading(__('No AI activity yet'))
            ->emptyStateDescription(__('When someone connects an assistant over MCP, everything it does is logged here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMcpActivities::route('/'),
        ];
    }
}
