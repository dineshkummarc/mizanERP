<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Filament\Resources\Projects\Pages\EditProject;
use App\Modules\Projects\Filament\Resources\Projects\Pages\ListProjects;
use App\Modules\Projects\Filament\Resources\Projects\Pages\ViewProject;
use App\Modules\Projects\Filament\Resources\Projects\RelationManagers\EmployeesRelationManager;
use App\Modules\Projects\Models\Project;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Feature\Concerns\MakesProjects;

/**
 * The permission shape, asserted against real roles rather than a Gate::before
 * bypass: projects are a company-wide shared reference, so every employee sees
 * and edits them, while deletion and on-demand checks stay privileged.
 */
class ProjectAuthorizationTest extends AccountingTestCase
{
    use InteractsWithTenant;
    use MakesProjects;

    /**
     * Filament's tenant setter needs an authenticated user, so sign in first
     * and set the panel tenant afterwards.
     */
    private function actingAsRole(string $role, string $email): User
    {
        $user = $this->makeUser($role, $email);
        $this->actingAs($user);
        $this->setCurrentTenant();

        return $user;
    }

    public function test_an_employee_sees_every_project_including_ones_they_are_not_on(): void
    {
        $employeeUser = $this->actingAsRole('Employee', 'emp@test.local');

        $mine = $this->makeProject(['name' => 'Mine']);
        $theirs = $this->makeProject(['name' => 'Someone else\'s']);

        $this->assertTrue($employeeUser->can('viewAny', Project::class));

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_an_employee_can_create_and_edit_any_project(): void
    {
        $employeeUser = $this->actingAsRole('Employee', 'emp2@test.local');

        $project = $this->makeProject();

        $this->assertTrue($employeeUser->can('create', Project::class));
        $this->assertTrue($employeeUser->can('update', $project));
    }

    public function test_an_employee_can_manage_the_team_but_not_detach(): void
    {
        $employeeUser = $this->actingAsRole('Employee', 'emp3@test.local');

        $project = $this->makeProject();
        $member = $this->makeEmployee('EMP-MEMBER');
        $project->assign($member);

        Livewire::test(EmployeesRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ])
            ->assertTableActionVisible('endAssignment', record: $member->getKey())
            ->assertTableActionHidden('detach', record: $member->getKey());
    }

    public function test_an_employee_cannot_delete_a_project(): void
    {
        $employeeUser = $this->actingAsRole('Employee', 'emp4@test.local');

        $project = $this->makeProject();

        $this->assertFalse($employeeUser->can('delete', $project));
        $this->assertFalse($employeeUser->can('ProjectHealthCheck'));
    }

    public function test_an_employee_cannot_trigger_an_on_demand_check(): void
    {
        $employeeUser = $this->actingAsRole('Employee', 'emp5@test.local');

        $project = $this->makeProject();
        $this->makeEnvironment($project);

        Livewire::test(ViewProject::class, ['record' => $project->getKey()])
            ->assertActionHidden('check_prod');
    }

    /**
     * An accountant may read and edit projects, but firing an on-demand check
     * makes the server issue an outbound request — not finance work.
     */
    public function test_an_accountant_cannot_trigger_an_on_demand_check(): void
    {
        $accountant = $this->actingAsRole('Accountant', 'acct-proj@test.local');

        $this->assertTrue($accountant->can('viewAny', Project::class));
        $this->assertFalse($accountant->can('ProjectHealthCheck'));

        $project = $this->makeProject();
        $this->makeEnvironment($project);

        Livewire::test(ViewProject::class, ['record' => $project->getKey()])
            ->assertActionHidden('check_prod');
    }

    public function test_a_manager_can_trigger_an_on_demand_check(): void
    {
        $manager = $this->actingAsRole('Manager', 'mgr-proj@test.local');

        $project = $this->makeProject();
        $this->makeEnvironment($project);

        $this->assertTrue($manager->can('ProjectHealthCheck'));

        Livewire::test(ViewProject::class, ['record' => $project->getKey()])
            ->assertActionVisible('check_prod');
    }

    public function test_only_a_ceo_can_delete_and_only_an_unassigned_project(): void
    {
        $ceo = $this->makeUser('CEO', 'ceo-proj@test.local');
        $manager = $this->makeUser('Manager', 'mgr-del@test.local');

        $empty = $this->makeProject();
        $staffed = $this->makeProject();
        $staffed->assign($this->makeEmployee('EMP-STAFF'));

        $this->assertFalse($manager->can('delete', $empty));
        $this->assertTrue($ceo->can('delete', $empty));
        $this->assertFalse($ceo->can('delete', $staffed));
    }
}
