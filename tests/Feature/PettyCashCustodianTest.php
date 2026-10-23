<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\PettyCashBook;
use App\Modules\Accounting\Filament\Resources\Beneficiaries\Pages\CreateBeneficiary;
use App\Modules\Accounting\Filament\Resources\Beneficiaries\Pages\EditBeneficiary;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\PettyCashService;
use App\Modules\Core\Models\Bank;
use Database\Seeders\TransactionTypeSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The month-end replenishment is paid to a *beneficiary* carrying the petty cash
 * custodian flag — not to one of the company bank accounts, which is the obvious
 * place to go looking and the wrong one.
 *
 * The flag was in the migration, the model and both seeders but never on the
 * form, so a company that entered its beneficiaries by hand could not name a
 * custodian and Replenish Month failed every time, as a 500 rather than a message.
 */
class PettyCashCustodianTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private PettyCashService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);

        $this->service = app(PettyCashService::class);
        $this->service->topUp(now()->startOfMonth()->toDateString(), 1000);
    }

    private function makeBeneficiary(string $name, bool $custodian = false): Beneficiary
    {
        return Beneficiary::create([
            'name' => $name,
            'bank_id' => Bank::first()?->id,
            'account_no' => '000'.mt_rand(1000, 9999),
            'payment_type' => 'IBFT',
            'is_active' => true,
            'is_petty_cash_custodian' => $custodian,
        ]);
    }

    public function test_the_custodian_flag_is_on_the_beneficiary_form(): void
    {
        // The whole bug: without this field the flag could only ever be set by a
        // seeder, so the feature was unreachable for a real company.
        $this->actingAs($this->makeUser('Administrator', 'ben-form@test.local'));
        $this->setCurrentTenant();

        Livewire::test(CreateBeneficiary::class)
            ->assertFormFieldExists('is_petty_cash_custodian');
    }

    public function test_designating_a_custodian_from_the_form_makes_replenishment_work(): void
    {
        $beneficiary = $this->makeBeneficiary('Cash Holder');

        $this->actingAs($this->makeUser('Administrator', 'ben-edit@test.local'));
        $this->setCurrentTenant();

        Livewire::test(EditBeneficiary::class, ['record' => $beneficiary->getKey()])
            ->fillForm(['is_petty_cash_custodian' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($beneficiary->fresh()->is_petty_cash_custodian);

        $payment = $this->service->replenish(now());

        $this->assertSame($beneficiary->getKey(), $payment->payable_id);
        // The stable alias, which is what a morph column holds across the move.
        $this->assertSame(\App\Support\ModuleMap::alias(Beneficiary::class), $payment->payable_type);
        $this->assertSame(Payment::STATUS_DRAFT, $payment->status);
    }

    public function test_only_one_beneficiary_holds_the_flag(): void
    {
        // replenish() pays the first active custodian it finds, so two of them
        // would make the recipient depend on row order.
        $first = $this->makeBeneficiary('First Holder', custodian: true);
        $second = $this->makeBeneficiary('Second Holder', custodian: true);

        $this->assertFalse($first->fresh()->is_petty_cash_custodian);
        $this->assertTrue($second->fresh()->is_petty_cash_custodian);
        $this->assertSame(1, Beneficiary::where('is_petty_cash_custodian', true)->count());

        $this->assertSame($second->getKey(), $this->service->replenish(now())->payable_id);
    }

    public function test_an_inactive_custodian_does_not_count(): void
    {
        $custodian = $this->makeBeneficiary('Left The Company', custodian: true);
        $custodian->update(['is_active' => false]);

        $this->expectException(RuntimeException::class);

        $this->service->replenish(now());
    }

    public function test_the_missing_custodian_message_says_where_to_set_it(): void
    {
        $this->makeBeneficiary('Not The Custodian');

        try {
            $this->service->replenish(now());
            $this->fail('Expected replenishment to fail with no custodian set.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Beneficiaries', $e->getMessage());
            $this->assertStringContainsString('Petty cash custodian', $e->getMessage());
        }
    }

    public function test_replenishing_without_a_custodian_notifies_instead_of_erroring(): void
    {
        // The reported production failure: a RuntimeException escaped the action,
        // which only caught InvalidArgumentException, so the user got a 500 and a
        // stack trace in prod.ERROR rather than something they could act on.
        $this->makeBeneficiary('Not The Custodian');

        $user = $this->makeUser('Administrator', 'petty-notify@test.local');
        $this->actingAs($user);
        $this->setCurrentTenant();

        Livewire::test(PettyCashBook::class)
            ->callAction(TestAction::make('replenish'))
            ->assertNotified();

        $this->assertSame(
            0,
            Payment::where('transaction_type_id', TransactionType::byCode('petty-cash-replenishment')?->id)->count(),
            'Nothing may be paid out when there is nobody to pay.'
        );
    }
}
