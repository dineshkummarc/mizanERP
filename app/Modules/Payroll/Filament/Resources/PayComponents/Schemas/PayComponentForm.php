<?php

namespace App\Modules\Payroll\Filament\Resources\PayComponents\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Payroll\Models\PayComponent;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PayComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->required()
                ->maxLength(255)
                ->helperText('As it appears on the payslip and the client statement.'),

            TextInput::make('code')
                ->required()
                ->maxLength(64)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                // Reports and imports refer to it, so it is fixed once anything has
                // been paid under it.
                ->disabled(fn (?PayComponent $record): bool => (bool) $record?->payslipAmounts()->exists())
                ->helperText('A stable identifier, e.g. fuel_card. Cannot change once it has been paid.'),

            Select::make('kind')
                ->options([
                    PayComponent::KIND_EARNING => 'Earning — added to pay',
                    PayComponent::KIND_DEDUCTION => 'Deduction — taken off pay',
                ])
                ->default(PayComponent::KIND_EARNING)
                ->selectablePlaceholder(false)
                ->live()
                ->disabled(fn (?PayComponent $record): bool => (bool) $record?->is_column_backed)
                ->required(),

            Toggle::make('is_taxable')
                ->label('Taxable')
                ->default(true)
                ->visible(fn (Get $get): bool => $get('kind') === PayComponent::KIND_EARNING)
                ->helperText('Off for money that is not income — a reimbursement of the employee\'s own spending.'),

            // What the amount is per — docs/bexio-gap-plan.md Phase 5, item 20. Not offered on the
            // column-backed components: their arithmetic is payroll's own.
            Select::make('basis')
                ->label('The amount is')
                ->options([
                    PayComponent::BASIS_MONTHLY => 'Per month — paid as it stands',
                    PayComponent::BASIS_PER_HOUR => 'Per hour — times the hours attendance recorded',
                    PayComponent::BASIS_ANNUAL => 'Per year — paid whole in one month',
                ])
                ->default(PayComponent::BASIS_MONTHLY)
                ->selectablePlaceholder(false)
                ->live()
                ->visible(fn (?PayComponent $record): bool => ! $record?->is_column_backed)
                ->helperText(fn (Get $get): string => match ($get('basis')) {
                    PayComponent::BASIS_PER_HOUR => 'An hourly wage. Needs the Attendance module: without recorded hours it pays nothing.',
                    PayComponent::BASIS_ANNUAL => 'A 13th salary or an annual bonus: the month named below carries the whole amount, the others none.',
                    default => 'The package amount lands on every payslip.',
                }),

            Select::make('pays_in_month')
                ->label('Paid in')
                ->options(array_combine(PayComponent::MONTHS, PayComponent::MONTHS))
                ->visible(fn (Get $get): bool => $get('basis') === PayComponent::BASIS_ANNUAL)
                ->required(fn (Get $get): bool => $get('basis') === PayComponent::BASIS_ANNUAL),

            // Only offered on earnings, and only where it can do anything. A deduction
            // never pro-rates by attendance — being away does not reduce what somebody
            // owes — so the flag would be a switch with no effect.
            Toggle::make('prorates')
                ->label('Reduce this for unpaid absence')
                ->default(false)
                ->visible(fn (Get $get): bool => $get('kind') === PayComponent::KIND_EARNING
                    && modules()->enabled('attendance'))
                ->helperText(fn (): string => setting('payroll.prorate_on_attendance')
                    ? 'On, this scales with the days actually paid, exactly as the basic wage does. Leave it off for a fixed allowance that is paid whole whatever the attendance — a device or internet allowance usually is.'
                    : 'Has no effect until "Reduce pay for unpaid absence" is switched on in Company Settings, which it is not. Setting it now decides what happens if that is ever turned on.'),

            Select::make('account_id')
                ->label('Posts to')
                ->options(fn (): array => Account::where('allow_manual_entry', true)
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Account $a): array => [$a->id => $a->code.' '.$a->name])
                    ->all())
                ->searchable()
                ->helperText('Where it lands in the ledger. Required unless a payroll account key is given below — a component with nowhere to post makes a payslip impossible to post.'),

            TextInput::make('account_key')
                ->label('Payroll account key')
                ->maxLength(64)
                ->helperText('Optional: reuse an existing payroll mapping, e.g. bonus_overtime, instead of naming an account.'),

            TextInput::make('sort')
                ->numeric()
                ->default(100)
                ->helperText('Order on the payslip. The shipped components run 10–140.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Switch off to stop paying it, without touching payslips that already did.'),

            TextInput::make('description')->maxLength(255)->columnSpanFull(),
        ]);
    }
}
