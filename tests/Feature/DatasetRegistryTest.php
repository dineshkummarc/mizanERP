<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Reporting\EmployeeDataset;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use App\Modules\Payroll\Reporting\PayslipComponentDataset;
use App\Modules\Payroll\Reporting\PayslipDataset;
use App\Support\ModuleMap;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use App\Support\Reporting\DatasetRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The dataset registry — `docs/reports-expansion-plan.md` Phase 6, items 1 and 2.
 *
 * **The registry is the boundary, and a boundary is only a boundary if something checks it.** Item 1's promise
 * is "no raw SQL, no table it has not named, no relation it has not declared", and most of this file is that
 * promise turned into assertions over every dataset at once:
 *
 *  - every column and filter names a column the table really has, so a typo is a failing test rather than a
 *    500 the first time somebody picks that column;
 *  - every declared relation exists on the model, for the same reason;
 *  - a derived column can never be grouped or summed, which is what keeps item 5's "aggregation in SQL" true
 *    by construction rather than by the query builder remembering;
 *  - every permission is one the seeder actually creates. This one matters more than it looks: `can()` on an
 *    unknown permission returns *false*, so a typo would make a subject invisible to everybody and look like
 *    a licensing question.
 *
 * **And the row-level half** (item 2), which is the test the plan singles out: "a non-privileged user building
 * a report over payslips sees their own rows and their downline's, and nobody else's."
 */
class DatasetRegistryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** @return array<int, class-string<Dataset>> */
    private function datasets(): array
    {
        return ModuleMap::datasets();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'builder@test.local'));
        $this->setCurrentTenant();

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    // ─────────────────────────────── the registry exists and is complete ──

    /**
     * Every dataset on disk is declared by a module.
     *
     * The `OperationsOverview` lesson from Phase 5.7, applied before it can happen again: a class that exists
     * and is registered nowhere works perfectly in a test and is absent from the product. Enumerating the
     * registry cannot catch it, because an enumeration only sees what is registered — so this compares the
     * *files* against the registry.
     */
    public function test_every_dataset_file_is_declared_by_a_module(): void
    {
        $onDisk = [];

        foreach (File::glob(app_path('Modules/*/Reporting/*Dataset.php')) as $file) {
            $module = basename(dirname($file, 2));
            $onDisk[] = 'App\\Modules\\'.$module.'\\Reporting\\'.basename($file, '.php');
        }

        $this->assertNotSame([], $onDisk, 'no dataset files were found at all, so this test proves nothing');

        $this->assertSame(
            [],
            array_values(array_map('class_basename', array_diff($onDisk, $this->datasets()))),
            "these datasets exist but no module.php declares them — add them under 'datasets'",
        );
    }

    /** The eleven subjects the plan names are all there. */
    public function test_the_plans_subjects_are_all_declared(): void
    {
        $labels = array_map(fn (string $class): string => $class::label(), $this->datasets());

        foreach ([
            'Journal lines', 'Invoices', 'Invoice lines', 'Payslips', 'Payslip components',
            'Employees', 'Stock movements', 'Timesheet entries', 'Tickets', 'Opportunities', 'Leave days',
        ] as $subject) {
            $this->assertContains($subject, $labels, "the plan names {$subject} and nothing declares it");
        }
    }

    /**
     * A dataset's key is its alias, and the alias is locked.
     *
     * Item 3 stores this key in every definition built over the subject, so it is a storage format — checked
     * here as well as in `AliasLockTest`, because a dataset whose key is its class name would pass that test
     * on the day it shipped and break every saved report the day the class moved.
     */
    public function test_every_dataset_key_is_a_locked_alias(): void
    {
        $locked = json_decode(File::get(base_path('tests/alias-lock.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($this->datasets() as $class) {
            $key = $class::key();

            $this->assertNotSame($class, $key, class_basename($class).' stores its class name as its key');
            $this->assertArrayHasKey($key, $locked['datasets'] ?? [], "{$key} is not in the alias lock");
        }
    }

    // ─────────────────────────────── the boundary ──

    /**
     * Every column and filter names a real column of the dataset's own table.
     *
     * "No table it has not named" read the other way round: the registry may only name columns that exist.
     * Checked against the schema rather than against a list, so it stays true through a migration that
     * renames one — which is exactly when a declaration goes quietly wrong.
     */
    public function test_every_declared_column_exists_on_the_table(): void
    {
        $missing = [];

        foreach ($this->datasets() as $class) {
            $columns = Schema::getColumnListing($class::table());

            foreach ($class::columns() as $column) {
                foreach (array_filter([$column->select, $column->groupBy]) as $local) {
                    if (! in_array($local, $columns, true)) {
                        $missing[] = class_basename($class).'::'.$column->key.' → '.$class::table().'.'.$local;
                    }
                }
            }

            foreach ($class::filters() as $filter) {
                foreach ($filter->touches() as $local) {
                    // A period may reach through a declared relation, which the next test covers.
                    if (str_contains($local, '.')) {
                        continue;
                    }

                    if (! in_array($local, $columns, true)) {
                        $missing[] = class_basename($class).' filter '.$filter->key.' → '.$class::table().'.'.$local;
                    }
                }
            }
        }

        $this->assertSame([], $missing, "these declarations name columns that do not exist:\n".implode("\n", $missing));
    }

    /**
     * Every declared relation is a relation the model has.
     *
     * "No relation it has not declared" — and the model is the one doing the declaring, so this checks the
     * registry against it. A path is walked hop by hop, because `request.employee.name` is two relations and
     * one attribute and only the relations are checkable here.
     */
    public function test_every_declared_relation_exists_on_the_model(): void
    {
        $missing = [];

        foreach ($this->datasets() as $class) {
            $paths = [];

            foreach ($class::columns() as $column) {
                if ($column->relation !== null) {
                    $paths[] = $column->relation;
                }
            }

            foreach ($class::filters() as $filter) {
                foreach ($filter->touches() as $touched) {
                    if (str_contains($touched, '.')) {
                        $paths[] = $touched;
                    }
                }
            }

            if ($class::periodColumn() !== null && str_contains((string) $class::periodColumn(), '.')) {
                $paths[] = (string) $class::periodColumn();
            }

            foreach ($paths as $path) {
                $model = $class::model();
                $steps = explode('.', $path);
                array_pop($steps); // the last hop is an attribute, not a relation

                foreach ($steps as $step) {
                    if ($model === null || ! method_exists($model, $step)) {
                        $missing[] = class_basename($class).' → '.$path.' (no '.$step.'() on '.class_basename((string) $model).')';

                        continue 2;
                    }

                    $model = (new $model)->{$step}()->getRelated()::class;
                }
            }
        }

        $this->assertSame([], $missing, "these declarations name relations that do not exist:\n".implode("\n", $missing));
    }

    /**
     * A derived column can never be grouped, and can only ever be counted.
     *
     * The rule that keeps item 5 honest: aggregation happens in SQL, and a closure cannot. Enforced in
     * `DatasetColumn::derived()` rather than checked by the query builder, so this asserts the *declaration*
     * makes the mistake impossible rather than that some later caller avoids it.
     */
    public function test_a_derived_column_can_only_be_counted(): void
    {
        $derived = 0;

        foreach ($this->datasets() as $class) {
            foreach ($class::columns() as $column) {
                if ($column->derive === null) {
                    continue;
                }

                $derived++;

                $this->assertFalse($column->isGroupable(), class_basename($class).'::'.$column->key.' is derived and groupable');
                $this->assertSame([DatasetColumn::COUNT], $column->aggregates, class_basename($class).'::'.$column->key);
            }
        }

        $this->assertGreaterThanOrEqual(5, $derived, 'no derived columns were checked, so this test proves nothing');
    }

    /** A text column offers no sum, because a sum of statuses is not a figure. */
    public function test_only_figures_can_be_summed(): void
    {
        foreach ($this->datasets() as $class) {
            foreach ($class::columns() as $column) {
                if (! $column->canAggregate(DatasetColumn::SUM)) {
                    continue;
                }

                $this->assertTrue(
                    $column->isNumeric(),
                    class_basename($class).'::'.$column->key.' can be summed and is a '.$column->type,
                );
            }
        }
    }

    /** Column keys are unique within a dataset, because a definition stores them. */
    public function test_column_keys_are_unique_within_a_dataset(): void
    {
        foreach ($this->datasets() as $class) {
            $keys = array_map(fn (DatasetColumn $column): string => $column->key, $class::columns());

            $this->assertSame(
                array_unique($keys),
                $keys,
                class_basename($class).' declares the same column key twice',
            );

            $this->assertNotSame([], $keys, class_basename($class).' declares no columns');
        }
    }

    /** And so are filter keys. */
    public function test_filter_keys_are_unique_within_a_dataset(): void
    {
        foreach ($this->datasets() as $class) {
            $keys = array_map(fn (DatasetFilter $filter): string => $filter->key, $class::filters());

            $this->assertSame(array_unique($keys), $keys, class_basename($class).' declares the same filter key twice');
        }
    }

    /**
     * An unrecognised column key resolves to nothing.
     *
     * The whole reason a definition stores keys rather than column names: a request cannot name a column,
     * only ask for one this dataset already declared.
     */
    public function test_an_unknown_column_key_resolves_to_nothing(): void
    {
        $this->assertNull(EmployeeDataset::column('salary; drop table employees'));
        $this->assertNull(EmployeeDataset::column('manager_id'), 'a real database column is not a declared column key');
        $this->assertNotNull(EmployeeDataset::column('manager'));
    }

    /**
     * Every permission a dataset names is one the seeder creates.
     *
     * `can()` on an unknown permission returns false rather than throwing — spatie's `checkPermissionTo`
     * catches it — so a typo here makes a subject invisible to everybody and looks like a licensing problem.
     * Nothing else in the suite would catch it: `ModuleCoverageTest` mines literal `can('X')` calls, and these
     * are strings behind a method.
     */
    public function test_every_dataset_names_a_seeded_permission(): void
    {
        $seeded = array_column(\App\Support\ModuleManifest::all()['permissions'], 'name');

        foreach ($this->datasets() as $class) {
            $this->assertContains(
                $class::permission(),
                $seeded,
                class_basename($class).' needs permission '.$class::permission().', which nothing seeds',
            );
        }
    }

    /** Every dataset belongs to a module that exists, read from where the file sits. */
    public function test_every_dataset_belongs_to_a_real_module(): void
    {
        foreach ($this->datasets() as $class) {
            $this->assertArrayHasKey(
                $class::module(),
                config('modules', []),
                class_basename($class).' claims module '.$class::module(),
            );
        }
    }

    /** A period is either a real date column or a path through a declared relation — never a month name. */
    public function test_a_period_is_a_date(): void
    {
        $withPeriod = 0;

        foreach ($this->datasets() as $class) {
            $period = $class::periodColumn();

            if ($period === null) {
                continue;
            }

            $withPeriod++;

            if (str_contains($period, '.')) {
                continue; // the relation test above proves the path; the column type is the parent's business
            }

            $column = collect($class::columns())->first(fn (DatasetColumn $c): bool => $c->select === $period);

            $this->assertNotNull($column, class_basename($class)."'s period column {$period} is not a declared column");
            $this->assertSame(
                DatasetColumn::DATE,
                $column->type,
                class_basename($class)."'s period is a {$column->type}, which cannot be bounded by dates",
            );
        }

        $this->assertGreaterThanOrEqual(6, $withPeriod, 'almost nothing declared a period, so this test proves little');
    }

    /**
     * The subjects with no period say so, and it is not an oversight.
     *
     * Item 5 offers "a mandatory period filter **or** an explicit row cap", and these take the second
     * branch for reasons in their own docblocks: an employee is a state rather than an event, a payslip's
     * month is a *name*, and a contact is a party — a date range would hide every customer who did nothing
     * this month, which is the list somebody exporting contacts wants (docs/bexio-gap-plan.md Phase 2).
     * Asserted so that a future dataset cannot quietly join them without a reason.
     */
    public function test_only_the_subjects_without_dates_have_no_period(): void
    {
        $without = array_values(array_map(
            'class_basename',
            array_filter($this->datasets(), fn (string $class): bool => $class::periodColumn() === null),
        ));

        sort($without);

        $this->assertSame(['ContactDataset', 'EmployeeDataset', 'PayslipComponentDataset', 'PayslipDataset'], $without);
    }

    // ─────────────────────────────── the query is the model's ──

    /** The base query is the model's, so tenancy and every global scope apply. */
    public function test_the_base_query_is_the_models(): void
    {
        foreach ($this->datasets() as $class) {
            $this->assertInstanceOf($class::model(), $class::query()->getModel(), class_basename($class));
        }
    }

    /** A column reads through the model, so casts and accessors apply. */
    public function test_a_column_reads_through_the_model(): void
    {
        $employee = Employee::create([
            'name' => 'Aisha Khan',
            'employee_id' => 'E-001',
            'department' => 'Finance',
            'is_active' => true,
            'date_of_joining' => '2020-07-01',
        ]);

        $this->assertSame('Aisha Khan', EmployeeDataset::column('name')?->value($employee));
        $this->assertSame('Finance', EmployeeDataset::column('department')?->value($employee));

        // Derived, and it reads what the accessor casts rather than the raw string.
        $this->assertGreaterThan(5.0, (float) EmployeeDataset::column('length_of_service')?->value($employee));
    }

    /** A related column through a missing relation reads as nothing rather than throwing. */
    public function test_a_related_column_survives_a_missing_relation(): void
    {
        $employee = Employee::create(['name' => 'No manager', 'employee_id' => 'E-002', 'is_active' => true]);

        $this->assertNull(EmployeeDataset::column('manager')?->value($employee));
    }

    // ─────────────────────────────── item 2: row-level access ──

    /**
     * **The test item 2 asks for**: a non-privileged user reporting over payslips sees their own rows and
     * their downline's, and nobody else's.
     *
     * Three payslips, three employees: the user's own, somebody who reports to them, and somebody who does
     * not. An Employee-role user is deliberately used rather than a Manager — `EmployeeAccess::PRIVILEGED_ROLES`
     * includes Manager, so a manager would see everything and the test would pass for the wrong reason.
     */
    public function test_a_report_over_payslips_is_scoped_to_the_downline(): void
    {
        $user = $this->makeUser('Employee', 'lead@test.local');

        $me = Employee::create(['name' => 'Team lead', 'employee_id' => 'E-010', 'is_active' => true, 'user_id' => $user->getKey()]);
        $mine = Employee::create(['name' => 'Reports to me', 'employee_id' => 'E-011', 'is_active' => true, 'manager_id' => $me->getKey()]);
        $theirs = Employee::create(['name' => 'Somebody else', 'employee_id' => 'E-012', 'is_active' => true]);

        // firstOrCreate: a seeded chart of accounts brings its own components, and `code` is unique.
        $component = PayComponent::firstOrCreate(
            ['code' => 'bonus'],
            ['label' => 'Bonus', 'kind' => PayComponent::KIND_EARNING, 'account_key' => 'bonus_overtime'],
        );

        foreach ([$me, $mine, $theirs] as $employee) {
            $payslip = Payslip::create([
                'employee_id' => $employee->getKey(),
                'month' => 'January',
                'fiscal_year_id' => $this->fiscalYear->getKey(),
                'net_salary' => 1000,
            ]);

            PayslipComponent::create([
                'payslip_id' => $payslip->getKey(),
                'pay_component_id' => $component->getKey(),
                'amount' => 250,
            ]);
        }

        $this->actingAs($user);

        $employees = PayslipDataset::query()->pluck('employee_id')->all();

        sort($employees);

        $this->assertSame([$me->getKey(), $mine->getKey()], $employees, 'the builder read a payslip outside the downline');

        // And the components of those payslips, which have no employee_id of their own and would otherwise be
        // the way round the scoping above.
        $this->assertSame(
            [$me->getKey(), $mine->getKey()],
            PayslipComponentDataset::query()
                ->with('payslip')
                ->get()
                ->map(fn ($component) => $component->payslip->employee_id)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        );
    }

    /** And a privileged role sees everybody, which is the other half of inheriting the rule. */
    public function test_a_privileged_role_reports_over_everybody(): void
    {
        $me = Employee::create(['name' => 'One', 'employee_id' => 'E-020', 'is_active' => true]);
        $them = Employee::create(['name' => 'Two', 'employee_id' => 'E-021', 'is_active' => true]);

        foreach ([$me, $them] as $employee) {
            Payslip::create([
                'employee_id' => $employee->getKey(),
                'month' => 'January',
                'fiscal_year_id' => $this->fiscalYear->getKey(),
                'net_salary' => 1000,
            ]);
        }

        // The Administrator from setUp.
        $this->assertCount(2, PayslipDataset::query()->get());
    }

    /**
     * Every dataset with an employee dimension applies the access rule.
     *
     * Written against the *files* rather than against a list of three, because the failure this prevents is a
     * dataset added later over a subject with an `employee_id` and no `access()` override — which is a leak
     * that looks exactly like a working feature.
     */
    public function test_every_dataset_over_employee_rows_scopes_itself(): void
    {
        $unscoped = [];

        foreach ($this->datasets() as $class) {
            $hasEmployee = in_array('employee_id', Schema::getColumnListing($class::table()), true);
            $isEmployees = $class::model() === Employee::class;

            if (! $hasEmployee && ! $isEmployees) {
                continue;
            }

            $overrides = (new ReflectionClass($class))->getMethod('access')->getDeclaringClass()->getName();

            if ($overrides !== $class) {
                $unscoped[] = class_basename($class);
            }
        }

        $this->assertSame(
            [],
            $unscoped,
            "these datasets hold employee rows and inherit no access(): \n".implode("\n", $unscoped),
        );
    }

    // ─────────────────────────────── availability ──

    /** A dataset whose module is switched off is not available. */
    public function test_a_dataset_needs_its_module(): void
    {
        $this->assertArrayHasKey(PayslipDataset::key(), DatasetRegistry::available());

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'payroll'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertArrayNotHasKey(PayslipDataset::key(), DatasetRegistry::available());
        $this->assertNull(DatasetRegistry::find(PayslipDataset::key()));
    }

    /**
     * The module gate holds on its own.
     *
     * **This needs a dataset built for the purpose, and the reason is worth recording.** The obvious version
     * of this test — switch payroll off, assert payslips disappear — cannot fail: `ModuleAuthorization`
     * registers a `Gate::before` that *denies* any permission belonging to a disabled module, and it returns
     * false rather than null, so it short-circuits before anything else can allow it. Switching the module off
     * therefore makes `can('PayslipView')` false too, and the subject vanishes whether `isAvailable()` looks at
     * the module or not. A mutation proved exactly that: deleting the module check broke nothing.
     *
     * The check still earns its place, for the one shape none of the eleven happens to have: a dataset in one
     * module gated on **another** module's permission. Switch the dataset's own module off and the permission
     * is still granted, because it belongs to a module that is still on — so `isAvailable()` is the only thing
     * left that can hide a subject whose rows belong to a module this company does not have. That shape is
     * what the class below is: Payroll's module, Core's always-granted `ReportView`.
     */
    public function test_the_module_gate_holds_when_the_permission_belongs_elsewhere(): void
    {
        Gate::before(fn (): bool => true);

        $this->assertTrue(CrossModuleDataset::isAvailable(), 'the fixture is not available even with payroll on');

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'payroll'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertFalse(
            CrossModuleDataset::isAvailable(),
            'a subject stayed available with its own module off, because its permission belongs to a module that is still on',
        );
    }

    /**
     * And the subject's own permission.
     *
     * **Which is where item 6's "payroll leak" turns out to be prevented by the combination and not by the
     * permission alone**, and that is worth having found. The Employee role *does* hold `PayslipView` — an
     * employee sees their own payslip — so the permission gate lets them build over payslips, and what keeps
     * that from being a leak is item 2: `EmployeeAccess` gives them one row, their own. Take either half away
     * and the subject is dangerous.
     *
     * What the permission gate refuses them is everything their role has no business reading at all:
     * invoices, journal lines, other people's records, the sales pipeline. Asserted as a difference from an
     * Administrator's list rather than as an empty set, so this says "these subjects are refused" rather than
     * "nothing works for this user".
     */
    public function test_a_dataset_needs_its_own_permission(): void
    {
        $everything = array_keys(DatasetRegistry::available());

        $this->actingAs($this->makeUser('Employee', 'nobody@test.local'));

        $allowed = array_keys(DatasetRegistry::available());

        $this->assertNotSame($everything, $allowed, 'an Employee may build over every subject an Administrator may');

        foreach ([
            \App\Modules\Accounting\Reporting\JournalLineDataset::class,
            \App\Modules\Invoicing\Reporting\InvoiceDataset::class,
            \App\Modules\Employees\Reporting\EmployeeDataset::class,
            \App\Modules\Crm\Reporting\OpportunityDataset::class,
            \App\Modules\Support\Reporting\TicketDataset::class,
        ] as $refused) {
            $this->assertNotContains(
                $refused::key(),
                $allowed,
                'an Employee may build a report over '.$refused::label(),
            );
        }

        // And payslips are allowed, which is the point above: the row scoping is what makes that safe.
        $this->assertContains(PayslipDataset::key(), $allowed);
    }

    /** The picker groups subjects by module, because that is how somebody looks for one. */
    public function test_the_labels_are_grouped_by_module(): void
    {
        $labels = DatasetRegistry::labels();

        $this->assertArrayHasKey('Payroll', $labels);
        $this->assertSame('Payslips', $labels['Payroll'][PayslipDataset::key()] ?? null);
    }
}

/**
 * A dataset whose module and whose permission belong to different modules.
 *
 * Exists only for `test_the_module_gate_holds_when_the_permission_belongs_elsewhere`, which explains why. Not
 * registered in any manifest — deliberately, since it is not a subject anybody should be able to report on —
 * so `key()` would throw and nothing calls it.
 */
class CrossModuleDataset extends Dataset
{
    public static function label(): string
    {
        return 'Cross-module fixture';
    }

    public static function description(): string
    {
        return 'A dataset in one module gated on another module\'s permission.';
    }

    public static function model(): string
    {
        return Payslip::class;
    }

    /** Core's, and Core is locked on — so only the module gate below can refuse this. */
    public static function permission(): string
    {
        return 'ReportView';
    }

    /** Payroll's, which the test switches off. */
    public static function module(): string
    {
        return 'payroll';
    }

    public static function periodColumn(): ?string
    {
        return null;
    }

    public static function columns(): array
    {
        return [DatasetColumn::make('month', 'Month')];
    }

    public static function filters(): array
    {
        return [];
    }
}
