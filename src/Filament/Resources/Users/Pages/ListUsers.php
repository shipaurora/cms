<?php

namespace Aurora\Filament\Resources\Users\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Users\Actions\InvitePeopleAction;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Filament\Resources\Users\Widgets\PendingInvitations;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    use WithoutBreadcrumbs;

    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return __('The people who work in the CMS, and the role that decides what each of them can do. Invite someone by email, or add them yourself.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add manually'))->color('gray')->icon('phosphor-user-plus'),
            // Reload so the pending invitations list appears (it is hidden while empty).
            InvitePeopleAction::make()->after(fn () => $this->redirect(UserResource::getUrl('index'))),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            PendingInvitations::class,
        ];
    }
}
