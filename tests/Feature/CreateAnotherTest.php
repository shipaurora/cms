<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Forms\Pages\CreateForm;
use Aurora\Filament\Resources\Menus\Pages\ListMenus;
use Aurora\Filament\Resources\Pages\Pages\CreatePage;
use Aurora\Filament\Resources\Roles\Pages\CreateRole;
use Aurora\Filament\Resources\Users\Pages\CreateUser;
use Aurora\Models\User;
use Aurora\Tests\TestCase;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class CreateAnotherTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_off_records_have_no_create_another_button(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        foreach ([CreatePage::class, CreateForm::class, CreateRole::class, CreateUser::class] as $page) {
            Livewire::actingAs($admin)
                ->test($page)
                ->assertActionDoesNotExist('createAnother');
        }
    }

    public function test_a_new_menu_has_no_create_another_either(): void
    {
        Livewire::actingAs($this->userWithRole(User::SUPER_ADMIN))
            ->test(ListMenus::class)
            ->assertActionExists('create', fn (CreateAction $action): bool => ! $action->canCreateAnother());
    }
}
