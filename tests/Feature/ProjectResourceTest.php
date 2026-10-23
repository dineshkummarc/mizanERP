<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Filament\Resources\Projects\Pages\CreateProject;
use App\Modules\Projects\Filament\Resources\Projects\Pages\EditProject;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Policies\ProjectPolicy;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenant;
use Tests\Feature\Concerns\MakesProjects;
use Tests\TestCase;

class ProjectResourceTest extends TestCase
{
    use InteractsWithTenant;
    use MakesProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();
    }

    public function test_create_persists_the_project_with_both_managers(): void
    {
        $primary = $this->makeEmployee('EMP-1');
        $secondary = $this->makeEmployee('EMP-2');

        Livewire::test(CreateProject::class)
            ->fillForm([
                'code' => 'PRJ-ERP-01',
                'name' => 'ERP rollout',
                'status' => Project::STATUS_ACTIVE,
                'manager_employee_id' => $primary->id,
                'secondary_employee_id' => $secondary->id,
                'environments' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::firstWhere('code', 'PRJ-ERP-01');

        $this->assertNotNull($project);
        $this->assertSame($primary->id, $project->manager_employee_id);
        $this->assertSame($secondary->id, $project->secondary_employee_id);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->makeProject(['code' => 'PRJ-DUP']);

        Livewire::test(CreateProject::class)
            ->fillForm([
                'code' => 'PRJ-DUP',
                'name' => 'Another',
                'status' => Project::STATUS_PLANNED,
                'environments' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'code' => 'PRJ-DATES',
                'name' => 'Backwards',
                'status' => Project::STATUS_PLANNED,
                'start_date' => '2026-06-01',
                'end_date' => '2026-05-01',
                'environments' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['end_date']);
    }

    public function test_the_same_employee_cannot_be_both_managers(): void
    {
        $employee = $this->makeEmployee('EMP-1');

        // Rejected by the form…
        Livewire::test(CreateProject::class)
            ->fillForm([
                'code' => 'PRJ-SAME',
                'name' => 'Same person',
                'status' => Project::STATUS_PLANNED,
                'manager_employee_id' => $employee->id,
                'secondary_employee_id' => $employee->id,
                'environments' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['secondary_employee_id']);

        // …and by the model, so a seeder or console script can't do it either.
        $this->expectException(InvalidArgumentException::class);
        $this->makeProject([
            'manager_employee_id' => $employee->id,
            'secondary_employee_id' => $employee->id,
        ]);
    }

    public function test_managers_are_optional(): void
    {
        $project = $this->makeProject();
        $this->assertNull($project->manager_employee_id);
        $this->assertCount(0, $project->managers());

        $primary = $this->makeEmployee('EMP-1');
        $project->update(['manager_employee_id' => $primary->id]);

        $this->assertCount(1, $project->refresh()->managers());
    }

    public function test_deleting_an_employee_nulls_the_designation_and_keeps_the_project(): void
    {
        $primary = $this->makeEmployee('EMP-1');
        $project = $this->makeProject(['manager_employee_id' => $primary->id]);

        $primary->delete();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'manager_employee_id' => null]);
    }

    /**
     * Asserted against the policy object rather than the Gate, because setUp
     * installs a Gate::before that would answer true for anything.
     */
    public function test_delete_is_blocked_while_an_assignment_exists(): void
    {
        $this->seed(PermissionSeeder::class);

        $deleter = User::factory()->create();
        $deleter->givePermissionTo('ProjectDelete');

        $employee = $this->makeEmployee('EMP-1');
        $project = $this->makeProject();
        $project->assign($employee);

        $policy = new ProjectPolicy;

        $this->assertFalse($policy->delete($deleter, $project));

        $project->employees()->detach();

        $this->assertTrue($policy->delete($deleter, $project->refresh()));
    }

    public function test_environments_persist_one_row_per_kind(): void
    {
        Livewire::test(CreateProject::class)
            ->fillForm([
                'code' => 'PRJ-ENV',
                'name' => 'With environments',
                'status' => Project::STATUS_ACTIVE,
                'environments' => [
                    ['kind' => 'prod', 'url' => 'https://prod.example.test', 'username' => 'admin', 'password' => 'p-secret', 'is_monitored' => true],
                    ['kind' => 'qual', 'url' => 'https://qual.example.test', 'is_monitored' => true],
                    ['kind' => 'dev', 'url' => 'http://localhost:8000', 'is_monitored' => false],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::firstWhere('code', 'PRJ-ENV');

        $this->assertCount(3, $project->environments);
        $this->assertSame('p-secret', $project->environment('prod')->password);
        $this->assertFalse($project->environment('dev')->is_monitored);
    }

    public function test_a_second_environment_of_the_same_kind_is_rejected(): void
    {
        $project = $this->makeProject();
        $this->makeEnvironment($project, ['kind' => ProjectEnvironment::KIND_PROD]);

        $this->expectException(UniqueConstraintViolationException::class);

        $project->environments()->create([
            'kind' => ProjectEnvironment::KIND_PROD,
            'url' => 'https://other.example.test',
        ]);
    }

    public function test_editing_a_url_leaves_the_password_and_keeps_it_out_of_the_audit_log(): void
    {
        $project = $this->makeProject();
        $environment = $this->makeEnvironment($project, ['password' => 'keep-me']);

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm([
                'environments' => [
                    [
                        'kind' => $environment->kind,
                        'url' => 'https://moved.example.test',
                        'username' => $environment->username,
                        'password' => $environment->password,
                        'is_monitored' => true,
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $environment = $project->refresh()->environment('prod');
        $this->assertSame('keep-me', $environment->password);
        $this->assertSame('https://moved.example.test', $environment->url);

        $properties = Activity::where('subject_type', ProjectEnvironment::class)
            ->get()
            ->map(fn ($activity) => json_encode($activity->properties))
            ->implode(' ');

        $this->assertStringNotContainsString('keep-me', $properties);
    }
}
