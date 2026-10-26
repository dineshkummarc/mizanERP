<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        // Nova $adminOnly closure: non-admins may edit only contact/bank
        // details; employment fields stay read-only for them.
        $adminOnly = fn (): bool => ! (auth()->user()?->isAdministrator() ?? false);

        return $schema
            ->components([
                TextInput::make('employee_id')
                    ->label('Employee ID')
                    ->required()
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                // BelongsTo user (Employee Name) — readonly in Nova; picker
                // restricted to users holding the Employee role.
                //
                // The row's own user is always in the list, whether or not they
                // still qualify. Users are shared across companies, so a query
                // that only offers current members would make an employee left
                // behind by a membership change show a blank name and fail
                // validation on save — on a field nobody can even edit.
                Select::make('user_id')
                    ->label('Employee Name')
                    ->relationship(
                        'user',
                        'name',
                        // The membership scope is lifted at the root so it can come
                        // back as one branch of an OR: the picker offers this
                        // company's people, an existing row keeps its own user.
                        fn (Builder $query, ?Employee $record): Builder => $query
                            ->acrossCompanies()
                            ->where(fn (Builder $eligible) => $eligible
                                ->where(fn (Builder $member) => $member
                                    ->inCurrentCompany()
                                    ->exceptPlatformAdmins()
                                    ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Employee')))
                                ->when($record?->user_id, fn (Builder $own, $userId) => $own
                                    ->orWhere($own->getModel()->getQualifiedKeyName(), $userId))),
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled()
                    ->dehydrated(false),

                // Transient user_name / user_email — resolve from the linked
                // user and write back to those model attributes (the model's
                // saving hook routes them to the user or a change request).
                TextInput::make('user_name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->dehydrated()
                    ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->name)),

                // The company address is also the login, so it lives on the
                // linked user; the personal one is a plain employee column.
                TextInput::make('user_email')
                    ->label('Company Email')
                    ->helperText('Used to sign in.')
                    ->email()
                    ->required()
                    ->dehydrated()
                    ->afterStateHydrated(fn (TextInput $component, ?Employee $record) => $component->state($record?->user?->email))
                    ->rule(fn (?Employee $record) => Rule::unique(User::class, 'email')->ignore($record?->user_id)),

                TextInput::make('personal_email')
                    ->label('Personal Email')
                    ->email()
                    ->maxLength(255),

                Select::make('is_active')
                    ->label('Status')
                    ->options([1 => 'Active', 0 => 'Inactive'])
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                // The three job facts below are lists this company writes for itself,
                // under Settings -> Dropdown Options. They were literal arrays here,
                // which made hiring a draughtsman a deploy. The record's own value is
                // passed so an entry since switched off still shows on the employee who
                // holds it instead of rendering blank and saving that blank back.
                Select::make('designation')
                    ->options(fn (?Employee $record): array => options('employees.designation', $record?->designation))
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                Select::make('department')
                    ->options(fn (?Employee $record): array => options('employees.department', $record?->department))
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                // A job fact like the two above, so the same admin-only gate: an
                // employee promoting themselves off probation would be a payroll
                // and a gratuity problem, not just a data one. Changing it writes
                // a dated history row, which is what makes "were they permanent
                // in March" answerable — see App\Modules\Employees\Services\JobHistory.
                //
                // Options from the company's own list, not spelled again here: two
                // screens each writing their own is how Company::TYPE_LABELS came to
                // exist. This was Employee::EMPLOYMENT_TYPES, whose four values are now
                // what the list is seeded with.
                Select::make('employment_type')
                    ->label('Employment Type')
                    ->options(fn (?Employee $record): array => options('employees.employment_type', $record?->employment_type))
                    ->placeholder('Not recorded')
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                // Reporting line. Only Admins/Managers/CEO may assign it. The
                // picker excludes the employee themselves and their whole
                // downline to prevent cycles; a matching validation rule
                // enforces the same on save.
                Select::make('manager_id')
                    ->label('Manager')
                    ->helperText('The employee this person reports to.')
                    ->options(function (?Employee $record) {
                        $exclude = $record
                            ? app(EmployeeAccess::class)->subtreeEmployeeIds($record->id)->all()
                            : [];

                        return Employee::query()
                            ->when($exclude, fn ($q) => $q->whereNotIn('id', $exclude))
                            ->with('user')
                            ->get()
                            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->display_label]);
                    })
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->rule(fn (?Employee $record) => function (string $attribute, $value, $fail) use ($record) {
                        if ($record && $value
                            && in_array((int) $value, app(EmployeeAccess::class)->subtreeEmployeeIds($record->id)->all(), true)) {
                            $fail('A manager cannot be the employee themselves or one of their own reports.');
                        }
                    })
                    ->visible(function (): bool {
                        $user = auth()->user();

                        // A super admin holds no role in most companies; see
                        // User::isAdministrator() for why that has to be said here.
                        return (bool) $user?->isSuperAdmin()
                            || (bool) $user?->hasAnyRole(['Administrator', 'Manager', 'CEO']);
                    }),

                DatePicker::make('date_of_birth')
                    ->label('Date of Birth')
                    ->native(false)
                    ->displayFormat('d-m-Y')
                    // A birth date in the future or beyond a working lifetime is
                    // a typo, not a person.
                    ->maxDate(now()->subYears(14))
                    ->minDate(now()->subYears(100)),

                DatePicker::make('date_of_joining')
                    ->label('Date of Joining'),

                /*
                 * When the employment ended.
                 *
                 * The column has been on `employees` since §3 of the plan — payroll, the headcount
                 * report and the experience letter all read it — and there has never been a field to
                 * write it with: it could only be set by a final settlement, which is a licensed
                 * module, or by hand in the database. That is the gap this fills.
                 *
                 * **It does not deactivate anybody.** `is_active` above is what every query filters
                 * on and it stays the flag; this records *when* it happened, not *whether* — see the
                 * migration that added the column. Setting one without the other is a real state (a
                 * notice period served out), so neither drives the other.
                 *
                 * Admin-only, like the job facts above: a date here rewrites the tense of every
                 * letter the company issues about this person.
                 */
                DatePicker::make('left_on')
                    ->label('Termination / Last Working Day')
                    ->helperText('The last day of employment. Leave blank while they are still employed. '
                        .'Once set, the experience letter and the income certificate are written in the past '
                        .'tense and bounded by this date — the Status above is a separate switch.')
                    // A leaving date before the joining date is a typo, and it would print a negative
                    // length of service on a letter somebody verifies.
                    ->afterOrEqual('date_of_joining')
                    ->disabled($adminOnly)
                    ->dehydrated(fn (): bool => ! $adminOnly()),

                TextInput::make('nic')
                    ->label('NIC')
                    ->required(),

                FileUpload::make('nic_front')
                    ->label('NIC (Front)')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->disk('public')
                    ->directory('nic')
                    ->visibility('public')
                    ->openable()
                    ->downloadable()
                    ->required()
                    ->imageEditor()
                    ->maxSize(4096),

                FileUpload::make('nic_back')
                    ->label('NIC (Back)')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->disk('public')
                    ->directory('nic')
                    ->visibility('public')
                    ->openable()
                    ->downloadable()
                    ->required()
                    ->imageEditor()
                    ->maxSize(4096),

                /*
                 * The directory lists the banks we transfer *out* to, so our own bank is
                 * deliberately not in it — which used to make an employee who banks with us
                 * unsaveable, because this field was required and had nothing to pick. It is
                 * optional now, and the short code below carries the answer instead.
                 */
                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->helperText('The bank we transfer out to. Leave blank if they bank with us — '
                        .'our own bank is not in this directory — and fill in the short code instead.'),

                TextInput::make('bank_short_code')
                    ->label('Bank short code')
                    ->maxLength(20)
                    ->visible(fn (Get $get): bool => blank($get('bank_id')))
                    ->required(fn (Get $get): bool => blank($get('bank_id')))
                    ->helperText('Only when no bank is chosen above — e.g. SCB for Standard Chartered. '
                        .'It is what the bank file carries in the beneficiary bank column.'),

                TextInput::make('bank_code')
                    ->label('Bank code')
                    ->maxLength(20)
                    ->visible(fn (Get $get): bool => blank($get('bank_id')))
                    ->helperText('Optional. Only used when no bank is chosen.'),

                /*
                 * One of these two has to be there, and *which* one depends on the bank: a
                 * transfer inside our own bank goes on the account number, everything else on
                 * the IBAN. Requiring both would block a perfectly payable employee whose
                 * other identifier nobody has, so the rule is "at least one", and the bank
                 * file preview refuses the row if it is the wrong one.
                 */
                TextInput::make('bank_account_no')
                    ->label('Bank A/C No')
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => blank($get('iban_no')))
                    ->helperText('What a transfer inside our own bank is sent on.'),

                TextInput::make('iban_no')
                    ->label('IBAN No')
                    ->live(onBlur: true)
                    ->required(fn (Get $get): bool => blank($get('bank_account_no')))
                    ->helperText('What a transfer to another bank is sent on.'),

                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->unique(table: Employee::class, column: 'phone', ignoreRecord: true)
                    ->validationMessages(['unique' => 'This phone number is already used by another employee.']),

                TextInput::make('secondary_phone')
                    ->label('Secondary Phone')
                    ->tel()
                    ->required(),

                TextInput::make('address_line_1'),

                TextInput::make('address_line_2'),

                Select::make('gender')
                    ->label('Gender')
                    ->options(fn (?Employee $record): array => options('employees.gender', $record?->gender)),

                ...CustomFieldsSchema::form(Employee::class),
            ]);
    }
}
