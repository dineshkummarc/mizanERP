<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrateExistingToTenantTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected array $tenantFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        $this->tenantDir = storage_path('framework/testing/tenants');
        File::ensureDirectoryExists($this->tenantDir);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();
        foreach ($this->tenantFiles as $f) {
            File::delete($f);
        }
        parent::tearDown();
    }

    protected function makeTenant(string $slug): Company
    {
        $file = "{$this->tenantDir}/{$slug}-".uniqid().'.sqlite';
        touch($file);
        $this->tenantFiles[] = $file;

        return Company::factory()->create(['slug' => $slug, 'database' => $file]);
    }

    public function test_copies_domain_data_and_backfills_memberships(): void
    {
        // Global permissions + roles exist (pre-teams single-DB world, team 1).
        $this->seed([\Database\Seeders\PermissionSeeder::class, \Database\Seeders\RoleSeeder::class]);

        // Existing single-DB data lives on the default (landlord) connection —
        // insert directly there (the Bank model now resolves to the tenant conn).
        $user = User::factory()->create();
        $user->assignRole('Accountant');
        DB::connection(config('database.default'))->table('banks')->insert([
            'bank_code' => 'HBL', 'bank_name' => 'Habib Bank', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Provision the default company's tenant DB (empty schema).
        $company = $this->makeTenant('default');
        $company->makeCurrent();
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
        Company::forgetCurrent();

        Artisan::call('tenancy:migrate-existing', ['--slug' => 'default']);

        // Data now present in the tenant DB.
        $company->makeCurrent();
        $this->assertSame(1, Bank::count());
        $this->assertSame('Habib Bank', Bank::first()->bank_name);
        Company::forgetCurrent();

        // Membership backfilled.
        $this->assertTrue($company->users()->where('users.id', $user->id)->exists());

        // Role remapped into the company's team.
        $company->makeCurrent();
        $this->assertTrue($user->fresh()->hasRole('Accountant'));
        Company::forgetCurrent();
    }
}
