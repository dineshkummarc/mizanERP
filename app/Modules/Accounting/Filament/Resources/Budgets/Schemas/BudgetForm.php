<?php

namespace App\Modules\Accounting\Filament\Resources\Budgets\Schemas;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Core\Models\FiscalYear;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BudgetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Budget')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            // Scoped to the year, matching the unique index on the
                            // table. An unscoped rule would refuse "2026-2027" to
                            // every year after the first; no rule at all lets the
                            // index throw a raw constraint violation at somebody
                            // who typed a duplicate name, which is a 500 where a
                            // sentence belongs.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get, ?Budget $record): Unique => $rule->where(
                                    'fiscal_year_id',
                                    $get('fiscal_year_id') ?? $record?->fiscal_year_id,
                                ),
                            )
                            ->helperText('What this plan is — "2026-2027", "2026-2027 revised after Q1".'),

                        Select::make('fiscal_year_id')
                            ->label('Fiscal Year')
                            ->options(fn (): array => FiscalYear::query()
                                ->orderByDesc('start_date')
                                ->pluck('name', 'id')
                                ->all())
                            ->default(fn () => FiscalYear::current()?->getKey())
                            ->required()
                            // The months a budget is made of come from the year's
                            // dates, and they are written when the plan is saved.
                            // Moving a saved budget to a different year would
                            // leave every line dated to months the new year does
                            // not contain, and the report would find no actuals
                            // for any of them.
                            ->disabledOn('edit')
                            ->dehydrated()
                            ->helperText('Fixed once the plan is saved — copy the budget to plan a different year.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Superseded plans stay here for comparison. Only active ones are offered on the report.'),

                        Textarea::make('notes')
                            ->rows(2)
                            ->nullable(),
                    ])
                    ->columns(2),

                Section::make('The plan')
                    ->description('One line per account, as a figure for the whole year. It is divided evenly across the year\'s months; adjust individual months on the Monthly Plan tab after saving.')
                    ->schema([
                        Repeater::make('plan')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('account_id')
                                    ->label('Account')
                                    ->options(fn (): array => static::plannableAccounts())
                                    ->searchable()
                                    ->required()
                                    // Two rows for one account would each claim the
                                    // same month, and the unique index would reject
                                    // the save with a database error rather than a
                                    // message anybody can act on.
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(2),

                                TextInput::make('amount')
                                    ->label('For the year')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->prefix('PKR'),
                            ])
                            ->columns(3)
                            ->addActionLabel('Add an account')
                            ->reorderable(false)
                            ->defaultItems(0),
                    ]),
            ]);
    }

    /**
     * Income and expense accounts that can actually receive a posting.
     *
     * Planning against a group header looks reasonable and reports nothing: the
     * ledger posts to its children, so the parent's actual is always zero and
     * the account shows as fully underspent for the whole year.
     *
     * @return array<int, string>
     */
    public static function plannableAccounts(): array
    {
        return Account::query()
            ->whereIn('type', ['income', 'expense'])
            ->postable()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [
                $account->id => "{$account->code} — {$account->name}",
            ])
            ->all();
    }
}
