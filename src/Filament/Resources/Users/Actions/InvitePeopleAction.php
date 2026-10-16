<?php

namespace Aurora\Filament\Resources\Users\Actions;

use Aurora\Models\Invitation;
use Aurora\Models\Role;
use Aurora\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * "Invite people": one or more email addresses, a role and an optional personal note.
 */
class InvitePeopleAction
{
    public static function make(): Action
    {
        return Action::make('invite')
            ->label(__('Invite people'))
            ->icon('phosphor-envelope-simple')
            ->visible(fn (): bool => auth()->user()->can('create', User::class))
            ->modalHeading(__('Invite people'))
            ->modalDescription(__('They get an email with a link to choose their name and password. The link works once and expires after :days days.', ['days' => Invitation::VALID_FOR_DAYS]))
            ->modalIcon('phosphor-envelope-simple')
            ->modalSubmitActionLabel(__('Send invitations'))
            ->modalWidth('lg')
            ->schema([
                TagsInput::make('emails')
                    ->label(__('Email addresses'))
                    ->placeholder(__('name@company.com, press Enter for each'))
                    ->splitKeys(['Tab', ',', ' ', ';'])
                    ->nestedRecursiveRules(['email:rfc'])
                    ->required(),
                Select::make('role_id')
                    ->label(__('Role'))
                    ->options(fn (): array => static::roles()->mapWithKeys(fn (Role $role): array => [$role->getKey() => $role->displayName()])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Role::query()->find($value)?->displayName())
                    ->default(fn (): ?string => static::roles()->firstWhere('name', 'Author')?->id)
                    ->helperText(fn ($state): ?string => Role::query()->find($state)?->displayDescription())
                    ->live()
                    ->native(false)
                    ->in(fn (): array => static::roles()->pluck('id')->all())
                    ->validationMessages(['in' => __('You cannot give people this role.')])
                    ->required(),
                Textarea::make('message')
                    ->label(__('Personal note (optional)'))
                    ->placeholder(__('Welcome aboard! Start with the Pricing page.'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $role = static::roles()->firstWhere('id', $data['role_id']);
                abort_if($role === null, 403);

                $emails = collect($data['emails'])->map(fn (string $email): string => Str::lower(trim($email)))->unique();
                $members = User::query()->whereIn('email', $emails)->pluck('email');

                foreach ($emails->diff($members) as $email) {
                    $invitation = Invitation::query()->pending()->firstOrNew(['email' => $email]);
                    $invitation->fill(['role_id' => $role->id, 'message' => $data['message'] ?? null, 'invited_by_id' => auth()->id(), 'expires_at' => now()])->save();
                    $invitation->send();
                }

                $sent = $emails->diff($members)->count();

                Notification::make()
                    ->title($sent > 0 ? "Sent {$sent} ".Str::plural('invitation', $sent) : __('No invitations sent'))
                    ->body($members->isNotEmpty() ? __('Already members: :names.', ['names' => $members->implode(', ')]) : null)
                    ->status($sent > 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * Roles the current user may hand out (User::canGrantRole()).
     *
     * @return Collection<int, Role>
     */
    protected static function roles(): Collection
    {
        // Only roles the inviter could hand out themselves.
        return Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role): bool => auth()->user()->canGrantRole($role))
            ->values();
    }
}
