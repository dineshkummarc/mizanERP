<?php

namespace Tests;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalarySlabSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

abstract class AccountingTestCase extends TestCase
{
    use RefreshDatabase;

    protected FiscalYear $fiscalYear;

    /** The company API callers made by actingAsApiUser() belong to. */
    protected ?Company $apiCompany = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
            FiscalYearSeeder::class,
            SalarySlabSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);

        $this->fiscalYear = FiscalYear::where('name', '2026-2027')->firstOrFail();
    }

    protected function makeUser(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => 1,
        ]);

        $user->assignRole($role);

        // A user who works in a company is a member of it. `users` is a shared
        // landlord table, so UserResource keeps row-level tenant scoping and that
        // membership is what makes them visible — to the Users list, to an
        // employee's user picker, to an approver lookup. Production attaches on
        // create; a fixture that skips it is testing a state the app cannot reach.
        if ($company = Filament::getTenant() ?? Company::current()) {
            $user->companies()->syncWithoutDetaching([$company->getKey()]);
        }

        return $user;
    }

    /**
     * A Sanctum caller: a user of the given role, member of one company.
     *
     * API requests run as the caller's company (ResolveCompanyFromUser), so a user with no membership is
     * refused before any controller runs. Every caller in a test joins the same company, so a second role
     * sees what the first one created. Not through Filament's tenant: setTenant() reads the panel's web
     * guard, which Sanctum::actingAs() never fills.
     */
    protected function actingAsApiUser(string $role, string $email): User
    {
        $user = $this->makeUser($role, $email);

        // The factory switches every module on for a new company, so nothing to license here.
        $this->apiCompany ??= Filament::getTenant() ?? Company::factory()->create();

        $user->companies()->syncWithoutDetaching([$this->apiCompany->getKey()]);

        // With the one ability a real token carries: RequireTokenAbility checks it on every API request, and
        // Sanctum's test double answers `can()` with false for anything not listed.
        Sanctum::actingAs($user, ['api']);

        return $user;
    }
}
