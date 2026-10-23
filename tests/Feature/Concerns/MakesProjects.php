<?php

namespace Tests\Feature\Concerns;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectEnvironment;

trait MakesProjects
{
    protected function makeEmployee(string $employeeId = 'EMP-1', ?User $user = null): Employee
    {
        return Employee::create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'is_active' => true,
        ]);
    }

    protected function makeProject(array $attributes = []): Project
    {
        return Project::create(array_merge([
            'code' => 'PRJ-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => 'Project '.fake()->word(),
            'status' => Project::STATUS_ACTIVE,
        ], $attributes));
    }

    protected function makeEnvironment(Project $project, array $attributes = []): ProjectEnvironment
    {
        return $project->environments()->create(array_merge([
            'kind' => ProjectEnvironment::KIND_PROD,
            'url' => 'https://prod.example.test',
        ], $attributes));
    }
}
