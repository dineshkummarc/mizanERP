<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ScheduledTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduledTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The schedule')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('What this standing entry is — "Office rent", "Vehicle loan instalment".'),

                        Select::make('interval_months')
                            ->label('Raise it')
                            ->options(ScheduledTransaction::INTERVALS)
                            ->default(1)
                            ->required(),

                        TextInput::make('day_of_month')
                            ->label('On day')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->default(1)
                            ->required()
                            ->helperText('A month too short for this day uses its last day, so the 31st still fires in February.'),

                        DatePicker::make('starts_on')
                            ->label('First due')
                            ->native(false)
                            ->default(now()->startOfMonth())
                            ->required(),

                        DatePicker::make('ends_on')
                            ->label('Stops after')
                            ->native(false)
                            ->afterOrEqual('starts_on')
                            ->nullable()
                            ->helperText('Leave empty for an open-ended arrangement.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Switch off to pause without losing the schedule or its history.'),
                    ])
                    ->columns(2),

                Section::make('The entry it raises')
                    ->description('Raised as a DRAFT on each due date, exactly as if somebody had typed it. It still needs submitting and approving before it reaches the ledger.')
                    ->schema([
                        Select::make('entry_type')
                            ->label('Entry type')
                            ->options([
                                'general' => 'General',
                                'adjusting' => 'Adjusting',
                                'closing' => 'Closing',
                                'reversing' => 'Reversing',
                            ])
                            ->default('general'),

                        TextInput::make('reference')
                            ->maxLength(255)
                            ->nullable(),

                        Textarea::make('memo')
                            ->rows(2)
                            ->nullable()
                            ->helperText('Copied onto every entry raised. The schedule\'s name is used when this is empty.')
                            ->columnSpanFull(),

                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                Select::make('account_id')
                                    ->label('Account')
                                    ->options(fn (): array => static::postableAccounts())
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(2),

                                TextInput::make('debit_amount')
                                    ->label('Debit')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),

                                TextInput::make('credit_amount')
                                    ->label('Credit')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),

                                TextInput::make('description')
                                    ->nullable()
                                    ->columnSpan(2),
                            ])
                            ->columns(4)
                            ->defaultItems(2)
                            ->minItems(2)
                            ->addActionLabel('Add a line')
                            ->reorderable()
                            ->orderColumn('sort')
                            // Checked here as well as in the service, because a
                            // schedule that cannot balance is silently skipped
                            // every night otherwise — the ledger simply never
                            // gets the rent, and nothing on the screen says why.
                            ->rules([
                                fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                    $debits = collect($value)->sum(fn ($line): float => (float) ($line['debit_amount'] ?? 0));
                                    $credits = collect($value)->sum(fn ($line): float => (float) ($line['credit_amount'] ?? 0));

                                    if (abs($debits - $credits) >= 0.005) {
                                        $fail(sprintf(
                                            'Debits and credits must be equal. Debits total %s, credits %s — a difference of %s.',
                                            number_format($debits, 2),
                                            number_format($credits, 2),
                                            number_format(abs($debits - $credits), 2),
                                        ));
                                    }

                                    if (abs($debits) < 0.005) {
                                        $fail('An entry of nothing is not worth scheduling — give the lines amounts.');
                                    }

                                    foreach ($value as $index => $line) {
                                        $debit = (float) ($line['debit_amount'] ?? 0);
                                        $credit = (float) ($line['credit_amount'] ?? 0);

                                        if ($debit > 0 && $credit > 0) {
                                            $fail('Line '.($index + 1).' has both a debit and a credit. Each line is one or the other.');
                                        }
                                    }
                                },
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Accounts that can actually receive a posting — the same set the manual
     * entry form offers.
     *
     * @return array<int, string>
     */
    public static function postableAccounts(): array
    {
        return Account::query()
            ->postable()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [
                $account->id => "{$account->code} — {$account->name}",
            ])
            ->all();
    }
}
