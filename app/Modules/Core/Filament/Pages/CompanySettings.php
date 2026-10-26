<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Support\KeyValueState;
use App\Support\SettingsSections;
use App\Support\TenantSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

/**
 * Per-company (per-tenant) settings editor. Loads the effective values via the
 * TenantSettings accessor (tenant override or config default) and persists
 * overrides into the current tenant's `settings` table.
 *
 * **Sections a module owns are contributed, not written here.** Two of them needed Accounting models — the
 * base currency reads `Currency` and asks `JournalEntryLine` whether anything is posted, and the payroll
 * account codes validate against `Account` — which made Core depend on Accounting for a settings form. They
 * now arrive through `App\Support\SettingsSections`; see docs/module-packaging-plan.md §9. What is left here
 * is the shell (access, `$data`, save dispatch, the notification, the status-page cache bust) and the
 * sections whose settings have no module class behind them.
 */
class CompanySettings extends Page
{
    protected string $view = 'filament.pages.company-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Company Settings';

    public ?array $data = [];

    /**
     * Shape of each editable iPayments field: a pattern the bank will accept and
     * a description used in the failure message.
     *
     * The file is rejected by SCB as a whole if a header field is malformed, and
     * that only surfaces at upload time — long after the save — so it is worth
     * catching here. Blank is always allowed: the config default applies.
     *
     * @var array<string, array{pattern: string, expects: string}>
     */
    private const IPAYMENTS_RULES = [
        'debit_account' => ['pattern' => '/^[A-Za-z0-9\- ]{5,34}$/', 'expects' => 'an account number (5-34 letters, digits, spaces or dashes)'],
        'debit_bank_id' => ['pattern' => '/^[A-Za-z]{4}[A-Za-z]{2}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/', 'expects' => 'a SWIFT/BIC code of 8 or 11 characters, e.g. SCBLPKKXXXX'],
        'debit_country' => ['pattern' => '/^[A-Za-z]{2}$/', 'expects' => 'a 2-letter ISO country code, e.g. PK'],
        'debit_city' => ['pattern' => '/^[A-Za-z]{3,20}$/', 'expects' => 'a city code or name, e.g. KHI'],
        'currency' => ['pattern' => '/^[A-Za-z]{3}$/', 'expects' => 'a 3-letter ISO currency code, e.g. PKR'],
        'payment_type' => ['pattern' => '/^[A-Za-z0-9]{2,10}$/', 'expects' => 'a payment type code, e.g. IBFT'],
        'salary_payment_type' => ['pattern' => '/^[A-Za-z0-9]{2,10}$/', 'expects' => 'a payment type code for salaries, e.g. PAY'],
        'processing_mode' => ['pattern' => '/^[A-Za-z]{2,4}$/', 'expects' => 'a processing mode, e.g. ON'],
        'invoice_format' => ['pattern' => '/^[0-9]{1,2}$/', 'expects' => 'a one or two digit format number'],
        'purpose_of_payment' => ['pattern' => '/^[0-9]{1,6}$/', 'expects' => 'a numeric purpose-of-payment code, e.g. 104'],
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('company-settings', 'Company Settings: Help'),
        ];
    }

    public function mount(): void
    {
        // Own state first, then whatever the contributed sections need — which is not always a settings key:
        // the base currency is a row in the currencies table. See App\Support\Contracts\SettingsSection.
        $this->form->fill([
            'petty_cash_float_amount' => setting('petty_cash.float_amount'),
            'reports_negatives_in_parentheses' => (bool) setting('reports.negatives_in_parentheses', false),
            'notifications_record_changes' => (bool) setting('notifications.record_changes', true),
            ...static::letterheadState(),
            'accounting_require_second_approver' => (bool) setting('accounting.require_second_approver'),
            'ipayments' => static::editableIpayments(),
            'projects_status_page_enabled' => (bool) setting('projects.status_page.enabled', false),
            'projects_status_page_token' => setting('projects.status_page.token'),
            'leave_weekend_days' => array_map('intval', (array) setting('leave.weekend_days', [6, 7])),
            'leave_year_basis' => setting('leave.year_basis'),
            'leave_carry_forward' => (bool) setting('leave.carry_forward'),
            'leave_carry_forward_expiry_months' => (int) setting('leave.carry_forward_expiry_months'),
            'leave_prorate_first_year' => (bool) setting('leave.prorate_first_year'),
            'leave_require_second_approver' => (bool) setting('leave.require_second_approver'),
            'leave_approval_chain' => array_values((array) setting('leave.approval_chain', [])),
            'leave_min_notice_enforced' => (bool) setting('leave.min_notice_enforced'),
            'leave_sandwich_rule' => setting('leave.sandwich_rule'),
            'payroll_prorate_on_attendance' => (bool) setting('payroll.prorate_on_attendance'),
            'payroll_proration_divisor' => setting('payroll.proration_divisor'),
            'payroll_pay_overtime' => (bool) setting('payroll.pay_overtime'),
            'attendance_overtime_multiplier' => setting('attendance.overtime_multiplier'),
            'attendance_retention_months' => setting('attendance.retention_months'),
            ...SettingsSections::fill(),
        ]);
    }

    /**
     * The flat iPayments fields, without the nested `own_bank` matching rules.
     *
     * A KeyValue field can only edit scalars: rendering `own_bank` gave a row
     * whose value was an array, and touching it would have replaced the SCB
     * matching config with a string — silently sending IBANs to SCB accounts.
     * It stays in config, where TenantSettings merges it back in.
     *
     * @return array<string, scalar>
     */
    protected static function editableIpayments(): array
    {
        return array_filter((array) setting('ipayments'), 'is_scalar');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components($this->sections());
    }

    /**
     * This page's own sections and the contributed ones, in one reading order.
     *
     * Keyed by sort rather than written in order, because the two Accounting sections are inserted at
     * positions 10 and 60 by `App\Support\SettingsSections` and the order has to be a stated decision on both
     * sides — currency first because it is what every other figure on the page means, the status page last
     * because it is the least consequential. Gaps are left so a module can land between two of these.
     *
     * @return array<int, mixed>
     */
    protected function sections(): array
    {
        $sections = [
            12 => [$this->letterheadSection()],
            15 => [$this->reportsSection()],
            16 => [$this->notificationsSection()],
            20 => [$this->pettyCashSection()],
            30 => [$this->approvalsSection()],
            40 => [$this->leaveSection()],
            45 => [$this->workingWeekSection()],
            50 => [$this->attendanceAndPaySection()],
            70 => [$this->ipaymentsSection()],
            80 => [$this->statusPageSection()],
        ];

        foreach (SettingsSections::sorted() as $contributed) {
            // Merged rather than assigned: a collision would otherwise drop one silently, and a module
            // choosing a sort cannot see what is already there.
            $sections[$contributed['sort']] = array_merge(
                $sections[$contributed['sort']] ?? [],
                $contributed['section']->components(),
            );
        }

        ksort($sections);

        return array_merge(...array_values($sections));
    }

    /**
     * How reports write their figures — `docs/reports-expansion-plan.md` Phase 4.3.
     *
     * In Core rather than in Accounting because it governs all fifty-one reports across every module, and
     * because Core is the one module always licensed — a preference filed behind a module a company has not
     * bought would be a preference it could not reach.
     */
    protected function reportsSection(): Section
    {
        return Section::make('Reports')
            ->description('How figures are written on every report, on screen and in the PDF.')
            ->schema([
                Toggle::make('reports_negatives_in_parentheses')
                    ->label('Show negatives in parentheses')
                    ->helperText(
                        'Accountants read (1,250) rather than -1,250. Off by default, and it never applies '
                        .'to the CSV export — a spreadsheet reads parentheses as text.'
                    ),
            ]);
    }

    /**
     * The letterhead fields, keyed as the form names them.
     *
     * @var array<string, string>
     */
    private const LETTERHEAD = [
        'company_legal_name' => 'company.legal_name',
        'company_address' => 'company.address',
        'company_phone' => 'company.phone',
        'company_email' => 'company.email',
        'company_website' => 'company.website',
        'company_registration_no' => 'company.registration_no',
        'company_ntn' => 'company.ntn',
        'company_signatory_name' => 'company.signatory_name',
        'company_signatory_title' => 'company.signatory_title',
        'company_logo_path' => 'company.logo_path',
        'company_signature_path' => 'company.signature_path',
    ];

    /** @return array<string, mixed> */
    protected static function letterheadState(): array
    {
        $state = [];

        foreach (self::LETTERHEAD as $field => $key) {
            $state[$field] = setting($key);
        }

        return $state;
    }

    /**
     * The company as somebody outside it reads it.
     *
     * **Nothing held these before.** `companies.name` is what the panel calls this tenant; a bank reading a
     * salary certificate needs the registered name, the office address and the two numbers it can check the
     * company against. They are per company and there is no sensible default for somebody else's NTN, so
     * they are blank until filled in — and `IncomeCertificate` refuses to print a letter while the four it
     * cannot do without are empty, rather than issuing one with a hole in the letterhead.
     */
    protected function letterheadSection(): Section
    {
        return Section::make('Letterhead')
            ->description('The registered details printed on documents that leave the company — the income certificate today, and anything else on company paper.')
            ->columns(2)
            ->schema([
                TextInput::make('company_legal_name')
                    ->label('Registered name')
                    ->maxLength(255)
                    ->placeholder('ERBIUMTECH (SMC-Private) Limited')
                    ->helperText('As registered, including the suffix. Not the short name the panel uses.'),

                TextInput::make('company_registration_no')
                    ->label('Incorporation / registration no.')
                    ->maxLength(64),

                TextInput::make('company_ntn')
                    ->label('NTN')
                    ->maxLength(32)
                    ->helperText('What the FBR and a bank check the company against.'),

                TextInput::make('company_phone')->label('Phone')->maxLength(64),

                TextInput::make('company_email')->label('Email')->email()->maxLength(255),

                TextInput::make('company_website')->label('Website')->maxLength(255),

                TextInput::make('company_address')
                    ->label('Registered office address')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('company_signatory_name')
                    ->label('Who signs')
                    ->maxLength(160)
                    ->helperText('The name printed above the signature line.'),

                TextInput::make('company_signatory_title')
                    ->label('Their title')
                    ->maxLength(160)
                    ->placeholder('Chief Executive Officer'),

                // On the tenant-scoped `public` disk (rerouted per company, served through the
                // access-checked /files route), same as expense-claim receipts. PNG/JPEG only:
                // Dompdf embeds those; an SVG would print in the browser and vanish in the PDF.
                // ponytail: replacing an upload leaves the old file under branding/ — small,
                // per-tenant, invisible; add deleteUploadedFileUsing if the directory ever matters.
                FileUpload::make('company_logo_path')
                    ->label('Logo')
                    ->disk('public')
                    ->directory('branding')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->maxSize(2048)
                    ->helperText('Printed at the top of payslips, invoices and letters, and shown as this company\'s panel brand. Without one, documents carry the company name as text — exactly as before.'),

                FileUpload::make('company_signature_path')
                    ->label('Signature / stamp')
                    ->disk('public')
                    ->directory('branding')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->maxSize(2048)
                    ->helperText('Printed in the signature block of payslips and both letters. Documents already carry a signature line either way.'),
            ]);
    }

    /**
     * What reaches the bell — `App\Modules\Core\Listeners\NotifyRecordAudience`.
     *
     * In Core rather than in a module because it governs every audited record in the application, and
     * because Core is the one module always licensed: a switch filed behind a module a company has not
     * bought would be a switch it could not reach.
     */
    protected function notificationsSection(): Section
    {
        return Section::make('Notifications')
            ->description('What the bell in the top right tells people about.')
            ->schema([
                Toggle::make('notifications_record_changes')
                    ->label('Tell people when a record of theirs changes')
                    ->helperText('On, somebody who owns a record — it names them, they created it, or they '
                        .'asked for it — hears when anybody else changes or deletes it. Never when they '
                        .'changed it themselves, and never by email: it goes to the bell, where the cost of '
                        .'one you did not need is a glance. Payslips, leave and expense claims are '
                        .'unaffected either way; each of those says something better for itself.'),
            ]);
    }

    protected function pettyCashSection(): Section
    {
        return Section::make('Petty Cash')
            ->schema([
                TextInput::make('petty_cash_float_amount')
                    ->label('Float Amount')
                    ->numeric()
                    ->required()
                    ->helperText('The imprest the petty cash box is restored to each month.'),
            ]);
    }

    protected function approvalsSection(): Section
    {
        return Section::make('Approvals')
            ->description('Who has to sign an entry off before it reaches the ledger.')
            ->schema([
                Toggle::make('accounting_require_second_approver')
                    ->label('Require a second person to approve journal entries')
                    ->helperText('On, whoever writes an entry cannot be the one who approves it. '
                        .'Turn it OFF only if one person runs the books alone: with nobody else to '
                        .'approve, entries wait forever while the money they describe has already '
                        .'moved. Self-approvals are recorded as such in the audit trail, and '
                        .'scheduled entries and loan instalments post themselves rather than '
                        .'queueing for an approver who does not exist. It starts at whatever '
                        .'this installation was set up with (ACCOUNTING_REQUIRE_SECOND_APPROVER '
                        .'in .env); saving here is this company answering for itself, and that '
                        .'answer stands whatever the installation default later becomes.'),
            ]);
    }

    /**
     * Leave policy.
     *
     * This page belongs to Core, which serves every company — so without the visible() guard a company that
     * never bought Leave would be offered leave policy to set. docs/hrms-plan.md §4.7 and its test.
     *
     * Everything here obeys one rule: a setting decides what happens next, never what already happened. Each
     * field's helper text says when its change takes effect, at the point of saving, because the alternative
     * is somebody switching a basis in June and asking why last month's leave moved.
     */
    protected function leaveSection(): Section
    {
        return Section::make('Leave')
            ->description('Policy this company sets for itself. Day counts, notice periods and which types exist are reference data — edit those under Leave Types.')
            ->visible(fn (): bool => modules()->enabled('leave'))
            ->schema([
                Select::make('leave_year_basis')
                    ->label('When the leave year starts')
                    ->options([
                        'calendar' => 'Calendar year (1 January – 31 December)',
                        'fiscal' => 'Fiscal year (1 July – 30 June)',
                        'anniversary' => 'Each employee\'s joining anniversary',
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->helperText('Changing this affects leave years opened from now on. Entitlements already open keep the window they were created with, so balances that are part-way through a year do not move.'),

                Toggle::make('leave_carry_forward')
                    ->label('Let unused days carry into the next leave year')
                    ->helperText('Off, unused days lapse at the year end and nothing is paid for them. On, each type carries up to its own "days that may carry forward" cap — a cap of 0 carries nothing even then. Switching this on does not give back days that have already lapsed.'),

                TextInput::make('leave_carry_forward_expiry_months')
                    ->label('Months carried days stay usable')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(12)
                    ->helperText('0, carried days last the whole year and lapse with it. 3 on a calendar year means "carried days lapse on 31 March": whatever is unused by then is voided, recorded as an adjustment on the entitlement. Applies to leave years opened from now on — a date already stamped on an open year does not move.'),

                Toggle::make('leave_prorate_first_year')
                    ->label('Pro-rate a mid-year joiner\'s first year')
                    ->helperText('On, somebody joining part-way through gets a share of the year\'s days: the joining month counts if they started on or before the 15th. Off, they get the full year from day one. Either way, entitlements already granted are not restated.'),

                Toggle::make('leave_require_second_approver')
                    ->label('Require somebody else to approve leave')
                    ->helperText('On, nobody may approve their own leave — a manager\'s own request routes to their manager. Turn it OFF only if there is nobody above to approve: the person at the top of the reporting tree otherwise cannot have leave approved at all. Self-approvals are recorded as such in the audit trail. It starts at whatever this installation was set up with (LEAVE_REQUIRE_SECOND_APPROVER in .env); saving here is this company answering for itself, and that answer stands whatever the installation default later becomes.'),

                TagsInput::make('leave_approval_chain')
                    ->label('Roles that approve after the first approval, in order')
                    ->placeholder('Add a role')
                    ->suggestions(fn (): array => Role::query()
                        ->where('company_id', app(PermissionRegistrar::class)->getPermissionsTeamId())
                        ->orderBy('name')
                        ->pluck('name')
                        ->all())
                    ->helperText('Empty, one approval decides a request, as it always has. Name roles here — HR, then CEO, say — and after the first approval the request waits for somebody holding each role in turn; only the last approval records the days and tells the employee. The "require somebody else" rule above applies at every step. A name that is not a role in this company is dropped when you save.'),

                Toggle::make('leave_min_notice_enforced')
                    ->label('Enforce the notice each leave type asks for')
                    ->helperText('Off, short notice is a warning and the request is still recorded — which is usually right, since casual and sick leave are asked for late by their nature. On, a request with less than the type\'s notice is refused outright.'),

                Select::make('leave_sandwich_rule')
                    ->label('Weekends and holidays inside a leave')
                    ->options([
                        'off' => 'Not counted — Friday plus Monday uses 2 days',
                        'enclosed' => 'Counted when enclosed — Friday plus Monday uses 4 days',
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->helperText('Applies to leave approved from now on. Requests already approved keep the days they were given — changing this does not restate leave somebody has already taken. Only days *between* two leave days are ever counted; a single Friday always costs one day.'),
            ]);
    }

    /**
     * Which weekdays the company does not work, for companies without work patterns.
     *
     * With `attendance` licensed, work patterns answer this per employee and the setting is not read, so
     * the section hides rather than show a control that does nothing. Without it, this is what decides how
     * many days a leave request consumes and how many working days a payslip says the month had.
     */
    protected function workingWeekSection(): Section
    {
        return Section::make('Working week')
            ->description('The days the company does not work. Leave requests skip them, and a payslip\'s working days are the month\'s remaining days less company holidays.')
            ->visible(fn (): bool => ! modules()->enabled('attendance')
                && (modules()->enabled('leave') || modules()->enabled('payroll')))
            ->schema([
                CheckboxList::make('leave_weekend_days')
                    ->label('Weekly off days')
                    ->options([
                        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                        5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
                    ])
                    ->columns(4)
                    ->helperText('Saturday and Sunday as shipped. A six-day week ticks Sunday alone. Public holidays are entered under Holidays and are taken off as well. Applies to leave approved and payslips created from now on; nothing already recorded is restated.'),
            ]);
    }

    /**
     * The two switches in this application that can reduce or increase a payslip.
     *
     * Both ship OFF, and the wording here is deliberately blunt about what turning them on does — somebody
     * reading this page is deciding whether people get paid less next month.
     *
     * Visible only when there is something to feed them: without `attendance` there is no work pattern to say
     * how long a month or a day is, so pro-rating would have nothing to divide by and overtime no rate to
     * derive.
     */
    protected function attendanceAndPaySection(): Section
    {
        return Section::make('Attendance and pay')
            ->description('Whether the attendance record changes what people are paid. Both of these are off until you turn them on.')
            ->visible(fn (): bool => modules()->enabled('attendance') && modules()->enabled('payroll'))
            ->schema([
                Toggle::make('payroll_prorate_on_attendance')
                    ->label('Reduce pay for unpaid absence')
                    ->helperText('OFF by default, and this is the setting to think hardest about. On, a month with unpaid absence pays less: the basic wage and any pay component marked "pro-rates" scale by the days paid. Fixed allowances, bonuses, overtime and every deduction are left alone. A month where attendance has not been filled in pays in FULL — an unmarked day is not a day anybody missed. Turning this on applies from the next payroll month; months already calculated keep the figures they were paid on.'),

                Select::make('payroll_proration_divisor')
                    ->label('Divide the month by')
                    ->options([
                        'working_days' => 'The working days in the month, from the work pattern',
                        'calendar_days' => 'The calendar days in the month',
                        'fixed_26' => 'A fixed 26 days',
                        'fixed_30' => 'A fixed 30 days',
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->visible(fn ($get): bool => (bool) $get('payroll_prorate_on_attendance'))
                    ->helperText('Recorded on each payslip as it is calculated, so changing this later never restates a month that has already been paid.'),

                Toggle::make('payroll_pay_overtime')
                    ->label('Pay for recorded overtime')
                    ->helperText('OFF by default. Until this is on, overtime is recorded and not paid — which is honest rather than lazy, because minutes have no defined value until a rate exists. On, the hourly rate is worked out from the basic wage and the work pattern\'s expected hours, multiplied by the figure below. The rate used is recorded on the payslip, so a later change to a package or a pattern does not restate a month already paid. Independent of pro-rating: overtime adds pay where pro-rating removes it.'),

                TextInput::make('attendance_overtime_multiplier')
                    ->label('Overtime multiplier')
                    ->numeric()
                    ->step(0.25)
                    ->minValue(1)
                    ->visible(fn ($get): bool => (bool) $get('payroll_pay_overtime'))
                    ->helperText('2.0 as shipped, because the Factories Act mandates double the ordinary rate. Provincial establishments differ — confirm what applies to you. Daily and weekly caps WARN and never reduce the amount: capping quietly would hide a compliance problem and underpay somebody at the same time.'),

                TextInput::make('attendance_retention_months')
                    ->label('Keep daily attendance for (months)')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Daily rows are pruned after this. It is the only table here that grows with usage rather than headcount, so it is cleaned up automatically — three years covers a payroll dispute, and longer has never been asked for.'),
            ]);
    }

    protected function ipaymentsSection(): Section
    {
        return Section::make('iPayments (Salary Bank File)')
            ->schema([
                KeyValue::make('ipayments')
                    ->label('iPayments Defaults')
                    ->keyLabel('Field')
                    ->valueLabel('Value')
                    ->addable(false)
                    ->deletable(false)
                    ->editableKeys(false)
                    ->helperText('Header fields for the salary bank file. A malformed value is only rejected when the file is uploaded to the bank, so it is checked here. Leave one blank to use the shipped default.')
                    ->rule(static function (): callable {
                        return static function (string $attribute, $value, callable $fail): void {
                            foreach (KeyValueState::map($value) as $field => $entry) {
                                // Nested config (the own_bank matching rules)
                                // is not editable here and never submitted.
                                if (! is_scalar($entry) || trim((string) $entry) === '') {
                                    continue;
                                }

                                $spec = self::IPAYMENTS_RULES[$field] ?? null;

                                if ($spec === null) {
                                    continue;
                                }

                                if (! preg_match($spec['pattern'], trim((string) $entry))) {
                                    $fail(sprintf(
                                        '"%s" must be %s — got "%s".',
                                        $field,
                                        $spec['expects'],
                                        $entry,
                                    ));
                                }
                            }
                        };
                    }),
            ]);
    }

    protected function statusPageSection(): Section
    {
        return Section::make('Public Status Page')
            ->description('Publishes the up/down state and uptime of environments marked "Show on public status page". Never publishes URLs, credentials or error details.')
            ->schema([
                Toggle::make('projects_status_page_enabled')
                    ->label('Enable public status page')
                    ->helperText('Off by default. The page is reachable only with the token below.'),

                TextInput::make('projects_status_page_token')
                    ->label('Access token')
                    ->helperText('Part of the URL. Changing it revokes every link already shared.')
                    ->suffixAction(
                        Action::make('generateStatusToken')
                            ->icon('heroicon-m-arrow-path')
                            ->label('Generate')
                            ->action(fn (Set $set) => $set('projects_status_page_token', Str::random(40)))
                    ),

                Placeholder::make('status_page_url')
                    ->label('Status page URL')
                    ->content(function (Get $get): string {
                        $token = $get('projects_status_page_token');
                        $company = Company::current();

                        if (! $token || ! $company) {
                            return 'Generate a token to get the URL.';
                        }

                        return route('status.show', ['company' => $company->slug, 'token' => $token]);
                    }),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        // Contributed sections write their own, first: the base currency is a row rather than a setting — it
        // is what the ledger's amounts mean — and the model refuses to change it once anything is posted
        // rather than trusting this screen to have disabled the field.
        SettingsSections::save($state);

        $settings = app(TenantSettings::class);
        $settings->set('petty_cash.float_amount', (float) $state['petty_cash_float_amount']);
        $settings->set('reports.negatives_in_parentheses', (bool) ($state['reports_negatives_in_parentheses'] ?? false));
        $settings->set('notifications.record_changes', (bool) ($state['notifications_record_changes'] ?? false));

        foreach (self::LETTERHEAD as $field => $key) {
            $settings->set($key, $state[$field] ?? null);
        }
        $settings->set('accounting.require_second_approver', (bool) $state['accounting_require_second_approver']);
        // Scalars only: the nested own_bank matching rules are not editable here,
        // and TenantSettings merges them back from config.
        $settings->set('ipayments', array_filter((array) $state['ipayments'], 'is_scalar'));
        $settings->set('projects.status_page.enabled', (bool) ($state['projects_status_page_enabled'] ?? false));
        $settings->set('projects.status_page.token', $state['projects_status_page_token'] ?: null);

        // Only when the section was actually rendered. Saving these for a company
        // without the module would write leave policy it can never see or change,
        // and the array keys are absent from $state when visible() hid the section.
        if (array_key_exists('leave_weekend_days', $state)) {
            $this->saveWorkingWeek($settings, $state);
        }

        if (array_key_exists('leave_year_basis', $state)) {
            $this->saveLeaveSettings($settings, $state);
        }

        // Same guard, same reason: the keys are absent from $state when visible() hid
        // the section, and writing pay policy for a company that cannot see it would be
        // a figure nobody chose.
        if (array_key_exists('payroll_prorate_on_attendance', $state)) {
            $this->savePayPolicySettings($settings, $state);
        }

        // A stale cached payload would otherwise keep serving after the page is
        // switched off or its token rotated.
        if (Company::current()) {
            Cache::forget('status-page:'.Company::current()->getKey());
        }

        Notification::make()->title('Settings saved.')->success()->send();
    }

    /**
     * The two settings that move money, with every change recorded and named.
     *
     * "Who turned pro-rating on, and when" is where an incident about a short payslip
     * begins — §4.7 says exactly that, and it is the reason this is logged separately
     * from an ordinary settings save rather than folded into one entry.
     *
     * @param  array<string, mixed>  $state
     */
    private function savePayPolicySettings(TenantSettings $settings, array $state): void
    {
        $changes = [];

        $booleans = [
            'payroll.prorate_on_attendance' => 'payroll_prorate_on_attendance',
            'payroll.pay_overtime' => 'payroll_pay_overtime',
        ];

        foreach ($booleans as $key => $field) {
            $was = (bool) setting($key);
            $now = (bool) ($state[$field] ?? false);

            if ($was !== $now) {
                $changes[$key] = ['from' => $was, 'to' => $now];
            }

            $settings->set($key, $now);
        }

        $settings->set('payroll.proration_divisor', $state['payroll_proration_divisor'] ?? 'working_days');
        $settings->set('attendance.overtime_multiplier', (float) ($state['attendance_overtime_multiplier'] ?? 2.0));
        $settings->set('attendance.retention_months', (int) ($state['attendance_retention_months'] ?? 36));

        if ($changes !== []) {
            activity('CompanySettings')
                ->causedBy(auth()->user())
                ->event('pay_policy_changed')
                ->withProperties($changes)
                // Spelled out rather than keyed, because this is the entry somebody
                // reads months later while working out why a payslip was short.
                ->log('Pay policy changed: '.implode(', ', array_map(
                    fn (string $key, array $change): string => $key.' '.($change['to'] ? 'ON' : 'OFF'),
                    array_keys($changes),
                    $changes,
                )));
        }
    }

    /**
     * The weekly off days, recorded when they change for the same reason as the leave policy below:
     * a payslip that says 26 working days where last month said 22 starts an argument this answers.
     *
     * @param  array<string, mixed>  $state
     */
    private function saveWorkingWeek(TenantSettings $settings, array $state): void
    {
        $now = array_values(array_unique(array_filter(
            array_map('intval', (array) ($state['leave_weekend_days'] ?? [])),
            fn (int $day): bool => $day >= 1 && $day <= 7,
        )));
        sort($now);

        $was = array_map('intval', (array) setting('leave.weekend_days', [6, 7]));
        sort($was);

        $settings->set('leave.weekend_days', $now);

        if ($was !== $now) {
            activity('CompanySettings')
                ->causedBy(auth()->user())
                ->event('working_week_changed')
                ->withProperties(['leave.weekend_days' => ['from' => $was, 'to' => $now]])
                ->log('Weekly off days changed');
        }
    }

    /**
     * Leave policy, with every change recorded.
     *
     * "Who turned this on, and when" is where an incident about a short payslip or a
     * disputed balance begins — the modules page already takes this position for
     * licence changes, and these settings move the same kind of number. Only what
     * actually changed is logged, because an entry per save on every field would bury
     * the one change somebody is looking for.
     *
     * @param  array<string, mixed>  $state
     */
    private function saveLeaveSettings(TenantSettings $settings, array $state): void
    {
        $keys = [
            'leave.year_basis' => ['leave_year_basis', 'string'],
            'leave.carry_forward' => ['leave_carry_forward', 'bool'],
            'leave.carry_forward_expiry_months' => ['leave_carry_forward_expiry_months', 'int'],
            'leave.prorate_first_year' => ['leave_prorate_first_year', 'bool'],
            'leave.require_second_approver' => ['leave_require_second_approver', 'bool'],
            'leave.min_notice_enforced' => ['leave_min_notice_enforced', 'bool'],
            'leave.sandwich_rule' => ['leave_sandwich_rule', 'string'],
        ];

        $changed = [];

        foreach ($keys as $key => [$field, $type]) {
            $cast = match ($type) {
                'bool' => fn (mixed $value): bool => (bool) $value,
                'int' => fn (mixed $value): int => (int) $value,
                default => fn (mixed $value): string => (string) $value,
            };

            $was = $cast(setting($key));
            $now = $cast($state[$field] ?? null);

            if ($was !== $now) {
                $changed[$key] = ['from' => $was, 'to' => $now];
            }

            $settings->set($key, $now);
        }

        if ($changed !== []) {
            activity('CompanySettings')
                ->causedBy(auth()->user())
                ->event('leave_policy_changed')
                ->withProperties($changed)
                ->log('Leave policy changed: '.implode(', ', array_keys($changed)));
        }
    }
}
