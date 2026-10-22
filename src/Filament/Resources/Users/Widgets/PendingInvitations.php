<?php

namespace Aurora\Filament\Resources\Users\Widgets;

use Aurora\Models\Invitation;
use Aurora\Models\User;
use Aurora\Support\UserBadge;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

class PendingInvitations extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('Pending invitations');
    }

    public static function canView(): bool
    {
        return auth()->user()->can('create', User::class) && Invitation::query()->pending()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Invitation::query()->pending()->with(['role', 'inviter'])->latest())
            ->description(__('People who have been invited but have not joined yet.'))
            ->paginated(false)
            ->columns([
                TextColumn::make('email')->weight('semibold')->icon('phosphor-envelope-simple')->copyable(),
                TextColumn::make('role.name')->label(__('Role'))->badge(),
                TextColumn::make('inviter.name')->label(__('Invited by'))->html()->formatStateUsing(fn (Invitation $record): ?HtmlString => UserBadge::html($record->inviter)),
                TextColumn::make('expires_at')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (Invitation $record): string => $record->isExpired() ? __('Expired') : __('Expires :date', ['date' => $record->expires_at->isoFormat('ll')]))
                    ->color(fn (Invitation $record): string => $record->isExpired() ? 'danger' : 'warning'),
                TextColumn::make('sent_at')->label(__('Sent'))->since()->color('gray'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('copyLink')
                        ->label(__('Copy link'))
                        ->icon('phosphor-link')
                        ->color('gray')
                        ->modalHeading(fn (Invitation $record): string => __('Invitation link for :email', ['email' => $record->email]))
                        ->modalDescription(__('Share it any way you like. It works once, for this person only.'))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Done'))
                        ->mountUsing(function (Invitation $record): void {
                            // An expired link is useless to share: give it a fresh week first.
                            if ($record->isExpired()) {
                                $record->update(['expires_at' => now()->addDays(Invitation::VALID_FOR_DAYS)]);
                            }
                        })
                        ->fillForm(fn (Invitation $record): array => ['link' => $record->url()])
                        ->schema([
                            TextInput::make('link')->hiddenLabel()->readOnly()->copyable(copyMessage: __('Link copied')),
                        ]),
                    Action::make('resend')
                        ->label(__('Resend'))
                        ->icon('phosphor-arrows-clockwise')
                        ->color('gray')
                        ->action(function (Invitation $record): void {
                            $record->send();
                            Notification::make()->title(__('Invitation sent again to :email', ['email' => $record->email]))->success()->send();
                        }),
                ]),
                DeleteAction::make()
                    ->label(__('Revoke'))
                    ->icon('phosphor-prohibit')
                    ->modalHeading(__('Revoke this invitation?'))
                    ->modalDescription(__('The link stops working immediately.'))
                    ->successNotificationTitle(__('Invitation revoked')),
            ]);
    }
}
