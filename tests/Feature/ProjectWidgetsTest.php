<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Filament\Resources\Projects\Widgets\ProjectHealthChart;
use App\Modules\Projects\Filament\Widgets\CertificateExpiryTable;
use App\Modules\Projects\Filament\Widgets\EnvironmentHealthOverview;
use App\Modules\Projects\Filament\Widgets\EnvironmentIncidentsTable;
use App\Modules\Projects\Filament\Widgets\MyProjectsOverview;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;
use Tests\Feature\Concerns\MakesProjects;

class ProjectWidgetsTest extends AccountingTestCase
{
    use InteractsWithTenant;
    use MakesProjects;

    private function actingAsRole(string $role, string $email): User
    {
        $user = $this->makeUser($role, $email);
        $this->actingAs($user);
        $this->setCurrentTenant();

        return $user;
    }

    public function test_the_health_overview_renders_for_a_plain_employee(): void
    {
        $this->actingAsRole('Employee', 'w1@test.local');

        $project = $this->makeProject();
        $up = $this->makeEnvironment($project, ['kind' => 'prod']);
        $up->recordCheck(true, 200, 30);
        $down = $this->makeEnvironment($project, ['kind' => 'qual']);
        $down->recordCheck(false, 503, 20, 'bad gateway');
        $this->makeEnvironment($project, ['kind' => 'dev']); // never checked

        $this->assertTrue(EnvironmentHealthOverview::canView());

        Livewire::test(EnvironmentHealthOverview::class)
            ->assertSuccessful()
            ->assertSee('Environments up')
            ->assertSee('Never checked');
    }

    public function test_the_incidents_widget_lists_confirmed_outages_only(): void
    {
        $this->actingAsRole('Employee', 'w2@test.local');

        $project = $this->makeProject(['name' => 'Broken project']);
        $environment = $this->makeEnvironment($project);

        $unconfirmed = $environment->incidents()->create([
            'started_at' => now()->subMinutes(5),
            'failure_count' => 1,
        ]);

        $confirmed = $environment->incidents()->create([
            'started_at' => now()->subHour(),
            'confirmed_at' => now()->subMinutes(50),
            'failure_count' => 5,
            'last_status_code' => 503,
        ]);

        Livewire::test(EnvironmentIncidentsTable::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$confirmed])
            ->assertCanNotSeeTableRecords([$unconfirmed]);
    }

    public function test_my_projects_shows_only_the_signed_in_users_projects(): void
    {
        $user = $this->actingAsRole('Employee', 'w3@test.local');
        $employee = $this->makeEmployee('EMP-ME', $user);

        $managed = $this->makeProject(['name' => 'I manage this', 'manager_employee_id' => $employee->id]);
        $assigned = $this->makeProject(['name' => 'I work on this']);
        $assigned->assign($employee);
        $unrelated = $this->makeProject(['name' => 'Nothing to do with me']);

        $this->assertTrue(MyProjectsOverview::canView());

        Livewire::test(MyProjectsOverview::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$managed, $assigned])
            ->assertCanNotSeeTableRecords([$unrelated]);
    }

    public function test_my_projects_is_hidden_for_a_user_without_an_employee_record(): void
    {
        $this->actingAsRole('Employee', 'w4@test.local');

        $this->assertFalse(MyProjectsOverview::canView());
    }

    public function test_the_certificate_widget_hides_when_nothing_is_expiring(): void
    {
        $this->actingAsRole('Employee', 'w5@test.local');

        $environment = $this->makeEnvironment($this->makeProject());
        $environment->forceFill(['ssl_expires_at' => now()->addYear()])->save();

        $this->assertFalse(CertificateExpiryTable::canView());

        $environment->forceFill(['ssl_expires_at' => now()->addDays(10)])->save();

        $this->assertTrue(CertificateExpiryTable::canView());

        Livewire::test(CertificateExpiryTable::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$environment]);
    }

    public function test_the_latency_chart_renders_for_a_project(): void
    {
        $this->actingAsRole('Employee', 'w6@test.local');

        $project = $this->makeProject();
        $environment = $this->makeEnvironment($project);

        foreach ([120, 140, 160] as $index => $latency) {
            $environment->checks()->create([
                'checked_at' => now()->subDays($index),
                'is_up' => true,
                'status_code' => 200,
                'latency_ms' => $latency,
            ]);
        }

        Livewire::test(ProjectHealthChart::class, ['record' => $project])
            ->assertSuccessful();
    }

    public function test_the_widgets_are_not_visible_without_project_view(): void
    {
        // A role with no project permissions at all.
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->setCurrentTenant();

        $this->assertFalse(EnvironmentHealthOverview::canView());
        $this->assertFalse(EnvironmentIncidentsTable::canView());
        $this->assertFalse(MyProjectsOverview::canView());
        $this->assertFalse(CertificateExpiryTable::canView());
    }
}
