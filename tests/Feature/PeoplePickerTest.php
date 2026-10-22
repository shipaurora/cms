<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Roles\Pages\EditRole;
use Aurora\Filament\Resources\Roles\RelationManagers\UsersRelationManager;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Support\PeoplePicker;
use Aurora\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class PeoplePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_are_found_by_name_email_or_job_title(): void
    {
        $ingrid = User::factory()->create(['name' => 'Ingrid Editor', 'email' => 'ingrid@example.com', 'job_title' => 'Content Lead']);
        $aksel = User::factory()->create(['name' => 'Aksel Author', 'email' => 'aksel@example.com', 'job_title' => 'Copywriter']);

        $this->assertSame([$ingrid->id], PeoplePicker::search('ingrid')->modelKeys());
        $this->assertSame([$aksel->id], PeoplePicker::search('aksel@')->modelKeys());
        $this->assertSame([$aksel->id], PeoplePicker::search('copy')->modelKeys());
    }

    public function test_adding_people_to_a_role_offers_only_those_without_it(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);
        $editor = Role::findByName('Editor');
        $member = $this->userWithRole('Editor');
        $newcomer = $this->userWithRole('Author');

        Livewire::actingAs($admin)
            ->test(UsersRelationManager::class, ['ownerRecord' => $editor, 'pageClass' => EditRole::class])
            ->mountAction(TestAction::make('addPeople')->table())
            ->assertMountedActionModalSee($newcomer->name)
            ->assertMountedActionModalDontSee($member->email)
            ->setActionData(['selection' => [$newcomer->id]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertTrue($newcomer->fresh()->hasRole('Editor'));
        $this->assertTrue($newcomer->fresh()->hasRole('Author'));
    }
}
