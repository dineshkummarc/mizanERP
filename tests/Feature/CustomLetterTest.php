<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\CustomLetter;
use App\Support\TenantSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Any wording an administrator types, on the company letterhead.
 *
 * Two things carry the weight here: only an administrator gets the action at all (free text on company
 * paper is a statement the company makes), and the text prints as typed — escaped, never trusted markup.
 */
class CustomLetterTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Administrator', 'hr-letter@test.local');
        $this->actingAs($this->admin);
        $this->setCurrentTenant();

        $settings = app(TenantSettings::class);
        foreach ([
            'company.legal_name' => 'ERBIUMTECH (SMC-Private) Limited',
            'company.address' => 'Office 5, Gulberg III, Lahore',
            'company.ntn' => '1234567-8',
            'company.signatory_name' => 'Muzafar Ali',
        ] as $key => $value) {
            $settings->set($key, $value);
        }

        $login = $this->makeUser('Employee', 'addressee@test.local');
        $login->update(['name' => 'Muhammad Hammad']);

        $this->employee = Employee::create([
            'user_id' => $login->getKey(),
            'employee_id' => 'EMP-014',
            'name' => 'Muhammad Hammad',
            'gender' => 'Male',
            'is_active' => true,
            'designation' => 'Senior Full Stack Developer',
            'department' => 'IT',
            'employment_type' => 'permanent',
            'date_of_joining' => '2024-03-01',
        ]);
    }

    /** The whole point of the gate: the siblings go to whoever may view the record, this one does not. */
    public function test_only_an_administrator_sees_the_action(): void
    {
        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->assertActionVisible('customLetter');

        // The employee may view their own record — and still gets no custom letter action.
        $this->actingAs(User::find($this->employee->user_id));

        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->assertActionHidden('customLetter');
    }

    public function test_an_administrator_downloads_the_letter_from_the_page(): void
    {
        Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->callAction(TestAction::make('customLetter'), [
                'to' => 'To whom it may concern',
                'subject' => 'No Objection Certificate',
                'body' => 'The company has no objection.',
            ])
            ->assertHasNoActionErrors();
    }

    public function test_the_letter_prints_the_typed_text_as_paragraphs(): void
    {
        $html = app(CustomLetter::class)->renderPdf($this->employee, [
            'to' => 'Muhammad Hammad',
            'subject' => 'Letter of Appreciation',
            'body' => "First paragraph,\nstill the first.\n\nSecond paragraph.",
        ])->html();

        $this->assertStringContainsString('SUBJECT: Letter of Appreciation', $html);
        $this->assertStringContainsString('Muhammad Hammad', $html);
        // A blank line is a paragraph break; a single newline is a line break inside one.
        $this->assertStringContainsString("First paragraph,<br />\nstill the first.", $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html);
        // Shares the sheet: the reference and the signatory come from the letterhead.
        $this->assertStringContainsString('EMP-014/LTR', $html);
        $this->assertStringContainsString('Muzafar Ali', $html);
    }

    /** Whatever is typed prints as text — never as markup. */
    public function test_the_body_is_escaped(): void
    {
        $html = app(CustomLetter::class)->renderPdf($this->employee, [
            'subject' => 'A <b>subject</b>',
            'body' => '<script>alert(1)</script>',
        ])->html();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('A <b>subject</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Free text or not, it is still company paper: a blank letterhead refuses, same as the siblings. */
    public function test_it_refuses_on_a_blank_letterhead(): void
    {
        $settings = app(TenantSettings::class);
        $settings->set('company.legal_name', '');

        $this->assertNotSame([], app(CustomLetter::class)->missingFor($this->employee));
    }
}
