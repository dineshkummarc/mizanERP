<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Multitenancy\CompanyProvisioner;
use Database\Seeders\PersonalBaselineSeeder;
use Database\Seeders\SalarySlabSeeder;
use Database\Seeders\TenantBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A newly provisioned company must come out usable, not merely created.
 *
 * The failure this exists for is quiet: a seeder is added to the codebase, wired
 * into nothing, and every new company thereafter is missing data that no test
 * notices because the tests seed what they need themselves. It has happened
 * twice — three companies were provisioned with no tax schedules at all because
 * TaxScheduleSeeder was added to the baseline after them, and the first personal
 * account came out with no banks and no spending categories.
 *
 * So these assert the reference data itself rather than the seeder list: a list
 * check would pass on a seeder that runs and produces nothing.
 */
class TenantBaselineCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $provisionedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach ($this->provisionedFiles as $path) {
            if ($path && File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    private function provision(string $type): Company
    {
        $company = app(CompanyProvisioner::class)->provision(
            name: 'Baseline '.$type,
            creator: \App\Modules\Core\Models\User::factory()->create(),
            type: $type,
        );

        $this->provisionedFiles[] = $company->database;
        $company->makeCurrent();

        return $company;
    }

    public function test_a_new_business_comes_out_ready_to_use(): void
    {
        $this->provision(Company::TYPE_BUSINESS);

        $this->assertGreaterThan(0, FiscalYear::count(), 'no fiscal year: nothing can be dated');
        $this->assertGreaterThan(0, Currency::count(), 'no currency: the books do not know what they are in');
        $this->assertGreaterThan(0, Account::count(), 'no chart of accounts: nothing can be posted');
        $this->assertGreaterThan(0, Bank::count(), 'no banks: no employee or beneficiary can be given one');
        $this->assertGreaterThan(0, TransactionType::count(), 'no categories: petty cash and payments cannot be booked');
        $this->assertGreaterThan(0, TaxSchedule::count(), 'no tax brackets');
        $this->assertGreaterThan(0, \App\Modules\Payroll\Models\SalarySlab::count(), 'no salary slabs: payroll cannot tax anybody');
    }

    public function test_a_new_personal_account_comes_out_ready_to_use(): void
    {
        $this->provision(Company::TYPE_PERSONAL);

        $this->assertGreaterThan(0, FiscalYear::count());
        $this->assertGreaterThan(0, Currency::count());
        $this->assertGreaterThan(0, Account::count());
        $this->assertGreaterThan(0, Bank::count(), 'a person has a bank account too');
        $this->assertGreaterThan(0, TransactionType::count(), 'no spending categories');
        $this->assertGreaterThan(0, TaxSchedule::count(), 'no tax brackets, so the estimate cannot run');
    }

    public function test_a_personal_accounts_categories_point_at_its_own_chart(): void
    {
        $this->provision(Company::TYPE_PERSONAL);

        // The trap this guards, stated as the specific wrong answers rather than
        // a name-matching heuristic: the business categories are keyed to the
        // business chart, where 5700 is Office Rent and 5600 is Food. In the
        // personal chart 5700 is Household & Maintenance and 5600 is Medical. Seed
        // the wrong set and rent posts to maintenance and groceries to medical,
        // silently, with nothing on the screen to show for it.
        $expected = [
            'rent' => '5200',
            'food' => '5100',
            'education' => '5300',
            'domestic-staff' => '5350',
            'medical' => '5600',
            'household' => '5700',
        ];

        $wrong = [];

        foreach ($expected as $code => $accountCode) {
            $type = TransactionType::with('account')->where('code', $code)->first();

            if ($type === null) {
                $wrong[] = "{$code}: no such category";

                continue;
            }

            if ($type->account?->code !== $accountCode) {
                $wrong[] = "{$code} -> {$type->account?->code} {$type->account?->name} (expected {$accountCode})";
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'These personal spending categories post to the wrong account, which',
            'means they were seeded against the business chart:',
            '',
            ...$wrong,
        ]));

        // And none may dangle: a category with no account cannot be booked at all
        // (PettyCashService refuses it), so it is a dead option on a form.
        $this->assertSame(
            0,
            TransactionType::whereNull('account_id')->count(),
            'a spending category points at no account',
        );
    }

    public function test_a_personal_account_gets_no_payroll_slabs(): void
    {
        $this->provision(Company::TYPE_PERSONAL);

        // Absent on purpose, not forgotten: a personal account does not run
        // payroll, and the top-up command reports its own gaps per tenant type
        // so this does not read as missing data.
        $this->assertSame(0, \App\Modules\Payroll\Models\SalarySlab::count());
    }

    public function test_the_baseline_lists_hold_only_real_seeders(): void
    {
        // Guards the guard: a typo'd or deleted class in either list would
        // otherwise only surface when somebody next created a company.
        foreach ([TenantBaselineSeeder::seeders(), PersonalBaselineSeeder::seeders()] as $list) {
            $this->assertNotEmpty($list);

            foreach ($list as $seeder) {
                $this->assertTrue(class_exists($seeder), "{$seeder} does not exist");
            }
        }
    }

    /**
     * The chart a company has been posting to is not ours to rewrite.
     *
     * This is the regression for a real failure. Every baseline seeder was
     * assumed safe to re-run because it was firstOrCreate *or updateOrCreate* —
     * and updateOrCreate does not leave rows alone, it overwrites them. So each
     * db:seed and each tenants:seed-baseline stamped this file's idea of the
     * chart back over the company's: an account renamed to "Consulting Revenue"
     * with journal entries against it silently became "Sales Revenue" again.
     *
     * Behavioural rather than a source grep on purpose. The grep below cannot
     * see an overwrite, which is exactly how this survived: it looks for
     * ->delete(), and updateOrCreate destroys data without ever calling it.
     */
    public function test_re_running_the_baseline_leaves_an_existing_chart_alone(): void
    {
        $this->provision(Company::TYPE_BUSINESS);

        // Rename an account and post to it, the way a company that has been
        // using the system for a while would have.
        $account = Account::where('code', '4200')->firstOrFail();
        $account->update(['name' => 'Consulting Revenue']);

        $entry = \App\Modules\Accounting\Models\JournalEntry::create([
            'entry_number' => 'JV-BASELINE-1',
            'entry_date' => now()->toDateString(),
        ]);

        \App\Modules\Accounting\Models\JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'credit_amount' => 1000,
        ]);

        // What tenants:seed-baseline re-runs over an existing company.
        foreach (TenantBaselineSeeder::seeders() as $seeder) {
            if ($seeder === SalarySlabSeeder::class) {
                continue; // the one seeder that is destructive by design
            }

            (new $seeder)->run();
        }

        $this->assertSame(
            'Consulting Revenue',
            $account->fresh()->name,
            'Re-running the baseline renamed an account that has journal entries against it.',
        );
    }

    /**
     * A personal account must never be seeded the business chart.
     *
     * db:seed used to run one hardcoded list over every company, so it seeded
     * the business chart into a personal account — renaming 4000 Salary to
     * "Income" and 1000 Cash in Hand to "Assets", then dying on the guard in
     * Account::booted() when it tried to hang 4100 under a 4000 that already had
     * a salary posted to it. CompanyProvisioner and tenants:seed-baseline both
     * routed on the company's profile already; db:seed was the one that did not.
     */
    public function test_db_seed_routes_each_company_to_its_own_chart(): void
    {
        $seeder = new \Database\Seeders\DatabaseSeeder;

        $personal = Company::factory()->make(['type' => Company::TYPE_PERSONAL, 'profile' => null]);
        $business = Company::factory()->make(['type' => Company::TYPE_BUSINESS, 'profile' => null]);

        $personalList = $seeder->seedersFor($personal);
        $businessList = $seeder->seedersFor($business);

        $this->assertContains(\Database\Seeders\PersonalChartOfAccountsSeeder::class, $personalList);
        $this->assertNotContains(
            \Database\Seeders\ChartOfAccountsSeeder::class,
            $personalList,
            'db:seed would seed the business chart into a personal account.',
        );

        $this->assertContains(\Database\Seeders\ChartOfAccountsSeeder::class, $businessList);
        $this->assertNotContains(
            \Database\Seeders\PersonalChartOfAccountsSeeder::class,
            $businessList,
        );

        // The categories are keyed to their own chart's codes, so the pair has
        // to travel together — see test_a_personal_accounts_categories_point_at_its_own_chart.
        $this->assertContains(\Database\Seeders\PersonalTransactionTypeSeeder::class, $personalList);
        $this->assertNotContains(\Database\Seeders\TransactionTypeSeeder::class, $personalList);

        // And a personal account gets none of the business-only dummy data:
        // pay components describe payroll it does not run, and the tax rates
        // post to 2150 Sales Tax Payable, which its chart has no such account for.
        $this->assertNotContains(\Database\Seeders\PayComponentSeeder::class, $personalList);
        $this->assertNotContains(\Database\Seeders\TaxRateSeeder::class, $personalList);
    }

    public function test_only_the_salary_slab_seeder_is_destructive_to_rerun(): void
    {
        // tenants:seed-baseline promises it only adds, and SalarySlabSeeder is
        // the single known exception it filters out. A new seeder that deletes
        // rows would break the promise silently, so the exception list is pinned
        // here.
        //
        // NOTE this grep catches only outright deletion. Overwriting a row is
        // just as destructive and is invisible here — updateOrCreate over a live
        // chart is what actually broke, and the test above is what covers it.
        $destructive = [];

        $all = array_unique(array_merge(TenantBaselineSeeder::seeders(), PersonalBaselineSeeder::seeders()));

        foreach ($all as $seeder) {
            $source = file_get_contents((new \ReflectionClass($seeder))->getFileName());

            if (preg_match('/->(delete|truncate|forceDelete)\(\)/', $source)) {
                $destructive[] = $seeder;
            }
        }

        $this->assertSame(
            [SalarySlabSeeder::class],
            $destructive,
            'A baseline seeder deletes rows. tenants:seed-baseline only filters SalarySlabSeeder, '
            .'so anything else here will destroy data it claims only to top up.',
        );
    }
}
