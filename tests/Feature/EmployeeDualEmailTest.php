<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Bank;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\EditEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Employees carry two addresses: the company one is also the login (stored on
 * the landlord `users` table) and the personal one is an employee column.
 */
class EmployeeDualEmailTest extends AccountingTestCase
{
    use InteractsWithTenant;

    public function test_form_exposes_a_company_and_a_personal_email_field(): void
    {
        Gate::before(fn () => true);

        $employee = $this->completeEmployee('fields@erbium.ch', 'EMP-FIELDS');

        $this->actingAs($this->makeUser('Administrator', 'admin-fields@test.local'));
        $this->setCurrentTenant();

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->assertFormFieldExists('user_email', fn ($field): bool => $field->getLabel() === 'Company Email')
            ->assertFormFieldExists('personal_email', fn ($field): bool => $field->getLabel() === 'Personal Email')
            ->assertFormSet([
                'user_email' => 'fields@erbium.ch',
                'personal_email' => 'old@gmail.com',
            ]);
    }

    public function test_personal_email_is_independent_of_the_login(): void
    {
        $user = $this->makeUser('Employee', 'login@erbium.ch');
        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-IND',
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'personal_email' => 'me@gmail.com',
        ]);

        // Changing the login leaves the personal address alone...
        $employee->user->update(['email' => 'new-login@erbium.ch']);
        $employee->refresh();

        $this->assertSame('new-login@erbium.ch', $employee->user->email);
        $this->assertSame('me@gmail.com', $employee->personal_email);

        // ...and the reverse.
        $employee->update(['personal_email' => 'me2@gmail.com']);
        $employee->refresh();

        $this->assertSame('me2@gmail.com', $employee->personal_email);
        $this->assertSame('new-login@erbium.ch', User::find($user->id)->email);
    }

    public function test_admin_edit_saves_personal_email_directly(): void
    {
        Gate::before(fn () => true);

        $employee = $this->completeEmployee('staff@erbium.ch', 'EMP-ADM-EDIT');

        $this->actingAs($this->makeUser('Administrator', 'admin-edit-emails@test.local'));
        $this->setCurrentTenant();

        Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
            ->assertFormSet(['personal_email' => 'old@gmail.com'])
            ->fillForm(['personal_email' => 'fresh@gmail.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('fresh@gmail.com', $employee->refresh()->personal_email);
        $this->assertSame('staff@erbium.ch', $employee->user->email);
        $this->assertSame(0, EmployeeChangeRequest::count());
    }

    /** Self-service edits are requestable, so personal_email routes to approval. */
    public function test_employee_editing_their_personal_email_needs_approval(): void
    {
        $user = $this->makeUser('Employee', 'self-mail@erbium.ch');
        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-SELF-MAIL',
            'gender' => 'Male',
            'phone' => '0300-2222222',
            'personal_email' => 'before@gmail.com',
        ]);

        $this->actingAs($user);
        $employee->update(['personal_email' => 'after@gmail.com']);

        // Untouched until approved.
        $this->assertSame('before@gmail.com', $employee->refresh()->personal_email);

        $request = EmployeeChangeRequest::firstOrFail();
        $this->assertSame('after@gmail.com', $request->requested_changes['personal_email']);
        $this->assertSame('before@gmail.com', $request->original_values['personal_email']);

        $manager = $this->makeUser('Manager', 'mgr-mail@test.local');
        $this->actingAs($manager);
        $request->approve($manager);

        $this->assertSame('after@gmail.com', $employee->refresh()->personal_email);
    }

    /** An employee with every field the form marks required already filled. */
    private function completeEmployee(string $email, string $employeeId): Employee
    {
        Storage::fake('public');
        Storage::disk('public')->put('nic/front.jpg', 'front');
        Storage::disk('public')->put('nic/back.jpg', 'back');

        $bank = Bank::create([
            'bank_code' => 'TSTB',
            'bank_name' => 'Test Bank',
            'bank_short_code' => 'TST',
        ]);

        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => $employeeId,
            'designation' => 'Backend Developer',
            'department' => 'IT',
            'gender' => 'Male',
            'phone' => '0300-1111111',
            'secondary_phone' => '0301-2222222',
            'nic' => '12345-1234567-1',
            'nic_front' => 'nic/front.jpg',
            'nic_back' => 'nic/back.jpg',
            'date_of_joining' => '2025-01-01',
            'bank_id' => $bank->id,
            'bank_account_no' => '0001112223334',
            'iban_no' => 'PK00TEST0000000000000000',
            'personal_email' => 'old@gmail.com',
            'is_active' => 1,
        ]);
    }
}
