<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ListEmployees;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Most of the suite runs every table in one database, which hides an entire
 * class of bug: `users` is a landlord table while `employees`, `mprs`, and
 * `payslips` are per-tenant, so any single statement spanning the two fails in
 * production with "Base table or view not found: ... .users doesn't exist".
 *
 * These tests migrate a genuinely separate tenant database — as production has —
 * so that Filament's relationship search/sort is exercised across the boundary.
 */
class CrossDatabaseUserColumnTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private string $tenantPath;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->tenantPath = database_path('tenants/cross-db-test.sqlite');
        File::ensureDirectoryExists(dirname($this->tenantPath));
        File::delete($this->tenantPath);
        File::put($this->tenantPath, '');

        config([
            'multitenancy.tenant_database_connection_name' => 'tenant',
            'database.connections.tenant.database' => $this->tenantPath,
        ]);
        DB::purge('tenant');

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $this->admin = $this->makeUser('Administrator', 'cross-admin@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant(Company::factory()->create(['database' => $this->tenantPath]));

        // Landlord users, tenant employee rows — the split that breaks.
        $this->employeeFor($this->namedUser('Zoe Zephyr', 'zoe@test.local'), 'EMP-Z');
        $this->employeeFor($this->namedUser('Adam Able', 'adam@test.local'), 'EMP-A');
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();
        File::delete($this->tenantPath);

        parent::tearDown();
    }

    private function namedUser(string $name, string $email): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => 1,
        ]);
        $user->assignRole('Employee');

        return $user;
    }

    private function employeeFor(User $user, string $employeeId): void
    {
        DB::connection('tenant')->table('employees')->insert([
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            'gender' => 'Male',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Guards the premise: without a separate database none of this is tested. */
    public function test_the_tenant_database_really_does_not_hold_users(): void
    {
        $tables = collect(DB::connection('tenant')->select("select name from sqlite_master where type='table'"))
            ->pluck('name');

        $this->assertTrue($tables->contains('employees'));
        $this->assertFalse($tables->contains('users'), 'users must live only in the landlord database');
    }

    public function test_the_employees_list_loads(): void
    {
        Livewire::test(ListEmployees::class)->loadTable()->assertSuccessful();
    }

    /** The reported failure: the paginator's count query spanned both databases. */
    public function test_searching_by_user_name_works_across_the_database_boundary(): void
    {
        Livewire::test(ListEmployees::class)
            ->loadTable()
            ->set('tableSearch', 'Zoe')
            ->assertSuccessful()
            ->assertSee('EMP-Z')
            ->assertDontSee('EMP-A');
    }

    public function test_searching_by_company_email_works_across_the_database_boundary(): void
    {
        Livewire::test(ListEmployees::class)
            ->loadTable()
            ->set('tableSearch', 'adam@test.local')
            ->assertSuccessful()
            ->assertSee('EMP-A')
            ->assertDontSee('EMP-Z');
    }

    public function test_a_search_matching_no_user_returns_nothing_rather_than_everything(): void
    {
        Livewire::test(ListEmployees::class)
            ->loadTable()
            ->set('tableSearch', 'nobody-by-this-name')
            ->assertSuccessful()
            ->assertDontSee('EMP-A')
            ->assertDontSee('EMP-Z');
    }

    public function test_sorting_by_user_name_works_across_the_database_boundary(): void
    {
        $order = fn (Testable $c): array => collect($c->instance()->getTableRecords()->all())
            ->map(fn ($record) => $record->employee_id)
            ->values()
            ->all();

        $ascending = Livewire::test(ListEmployees::class)
            ->loadTable()
            ->sortTable('user.name')
            ->assertSuccessful();

        $this->assertSame(['EMP-A', 'EMP-Z'], $order($ascending), 'Adam should sort before Zoe');

        $descending = $ascending->sortTable('user.name')->assertSuccessful();

        $this->assertSame(['EMP-Z', 'EMP-A'], $order($descending), 'direction should reverse');
    }

    /** Searching a plain tenant column must keep working alongside the above. */
    public function test_searching_a_tenant_column_still_works(): void
    {
        Livewire::test(ListEmployees::class)
            ->loadTable()
            ->set('tableSearch', 'EMP-Z')
            ->assertSuccessful()
            ->assertSee('EMP-Z')
            ->assertDontSee('EMP-A');
    }
}
