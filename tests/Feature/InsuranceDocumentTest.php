<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\ListEmployeeDocuments;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Services\DocumentExpiryCheck;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An insurance policy as a document that expires — `docs/bexio-gap-plan.md` Phase 5, item 19.
 *
 * The plan sketched a table of its own; a policy is a document with a number, a term and a scan, and
 * the expiry machinery was the point. What this pins: the kind exists, its three extra facts are kept,
 * and the same expiry check that watches a visa watches it.
 */
class InsuranceDocumentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'hr@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'insured@test.local')->id,
            'employee_id' => 'EMP-INS',
            'gender' => 'Female',
        ]);
    }

    public function test_a_policy_is_a_document_with_an_insurer_a_cover_and_a_premium(): void
    {
        $policy = EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'kind' => EmployeeDocument::KIND_INSURANCE,
            'number' => 'JLI-2026-00417',
            'insurer' => 'Jubilee Life',
            'cover_amount' => 2_000_000,
            'premium' => 48_000,
            'issued_on' => '2026-01-01',
            'expires_on' => now()->addDays(10)->toDateString(),
        ]);

        $policy->refresh();

        $this->assertTrue($policy->isInsurance());
        $this->assertContains(EmployeeDocument::KIND_INSURANCE, EmployeeDocument::KINDS);
        $this->assertSame('Jubilee Life', $policy->insurer);
        $this->assertSame(2_000_000.0, (float) $policy->cover_amount);
        $this->assertSame(48_000.0, (float) $policy->premium);
    }

    /** The same expiry check that watches a visa watches the policy — so the widget, report and reminders do too. */
    public function test_a_lapsing_policy_is_reported_with_the_other_documents(): void
    {
        $policy = EmployeeDocument::create([
            'employee_id' => $this->employee->id,
            'kind' => EmployeeDocument::KIND_INSURANCE,
            'number' => 'JLI-2026-00417',
            'insurer' => 'Jubilee Life',
            'expires_on' => now()->addDays(10)->toDateString(),
        ]);

        $expiring = app(DocumentExpiryCheck::class)->expiring();

        $this->assertTrue($expiring->contains(fn (array $row): bool => $row['document']->is($policy)));
        $this->assertSame(10, $expiring->first(fn (array $row): bool => $row['document']->is($policy))['days']);

        Livewire::test(ListEmployeeDocuments::class)->assertCanSeeTableRecords([$policy]);
    }
}
