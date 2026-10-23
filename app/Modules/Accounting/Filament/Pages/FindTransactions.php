<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * One question across the whole ledger: "every transaction over 50,000 against
 * this account between these dates".
 *
 * Until this existed the only way to ask it was the Account Register, one
 * account at a time, reading down the page. The register is still the right
 * screen for working *in* an account — it posts and edits. This one only finds.
 *
 * Deliberately over LINES rather than entries. A journal entry has no single
 * account or amount — it has two sides at least, and often more — so "over
 * 50,000 against Rent" is a question about a line. Searching entries would
 * return the whole entry for a match on any part of it, which is the wrong grain
 * for a search whose answer is "which postings".
 */
class FindTransactions extends Page implements HasTable
{
    use BelongsToModule;
    use InteractsWithTable;

    protected string $view = 'filament.pages.find-transactions';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $title = 'Find Transactions';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('JournalEntryView') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // No guard against an orphaned line here, deliberately:
                // journal_entry_lines.journal_entry_id is a foreign key with
                // cascade delete, so a line without its entry cannot exist. A
                // whereHas to be safe would be a subquery on every page load
                // buying protection against a state the database forbids.
                JournalEntryLine::query()
                    ->with(['account:id,code,name', 'journalEntry:id,entry_number,entry_date,status,memo,reference'])
            )
            ->columns([
                TextColumn::make('journalEntry.entry_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Entry')
                    ->searchable()
                    ->sortable()
                    ->url(fn (JournalEntryLine $record): ?string => $record->journal_entry_id
                        ? \App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource::getUrl('edit', ['record' => $record->journal_entry_id])
                        : null),

                TextColumn::make('account.code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('account.name')
                    ->label('Account')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('description')
                    ->label('Narration')
                    // A line often carries no description of its own; the entry's
                    // memo is what a human wrote about the transaction, and
                    // showing a column of blanks helps nobody.
                    ->state(fn (JournalEntryLine $record): ?string => $record->description ?: $record->journalEntry?->memo)
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('journalEntry.reference')
                    ->label('Reference')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('debit_amount')
                    ->label('Debit')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable()
                    ->placeholder('')
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? number_format((float) $state, 2) : '')
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 2)),

                TextColumn::make('credit_amount')
                    ->label('Credit')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? number_format((float) $state, 2) : '')
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 2)),

                TextColumn::make('journalEntry.status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        JournalEntry::STATUS_POSTED => 'success',
                        JournalEntry::STATUS_APPROVED => 'info',
                        JournalEntry::STATUS_PENDING => 'warning',
                        JournalEntry::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Accounts')
                    ->multiple()
                    ->options(fn (): array => Account::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Account $a): array => [$a->id => "{$a->code} — {$a->name}"])
                        ->all())
                    ->searchable()
                    ->preload(),

                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('to')->label('To')->native(false)->afterOrEqual('from'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query->whereHas(
                        'journalEntry',
                        fn (Builder $q): Builder => $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '<=', $d)),
                    ))
                    ->indicateUsing(function (array $data): array {
                        $parts = array_filter([
                            ($data['from'] ?? null) ? 'from '.$data['from'] : null,
                            ($data['to'] ?? null) ? 'to '.$data['to'] : null,
                        ]);

                        return $parts === [] ? [] : ['Dated '.implode(' ', $parts)];
                    }),

                Filter::make('amount')
                    ->schema([
                        TextInput::make('min')->label('At least')->numeric()->prefix('PKR'),
                        TextInput::make('max')->label('At most')->numeric()->prefix('PKR'),
                    ])
                    ->columns(2)
                    // Each line is a debit or a credit, never both, so "at least
                    // 50,000" means either side reaches it — and "at most" means
                    // neither side exceeds it. Written as two different shapes on
                    // purpose: the same OR on the max would match every line,
                    // because the empty side of every line is zero.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['min'] ?? null,
                            fn (Builder $q, $min): Builder => $q->where(
                                fn (Builder $inner) => $inner
                                    ->where('debit_amount', '>=', $min)
                                    ->orWhere('credit_amount', '>=', $min),
                            ),
                        )
                        ->when(
                            $data['max'] ?? null,
                            fn (Builder $q, $max): Builder => $q
                                ->where('debit_amount', '<=', $max)
                                ->where('credit_amount', '<=', $max),
                        ))
                    ->indicateUsing(function (array $data): array {
                        $parts = array_filter([
                            ($data['min'] ?? null) ? '≥ '.number_format((float) $data['min'], 2) : null,
                            ($data['max'] ?? null) ? '≤ '.number_format((float) $data['max'], 2) : null,
                        ]);

                        return $parts === [] ? [] : ['Amount '.implode(', ', $parts)];
                    }),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        JournalEntry::STATUS_DRAFT => 'Draft',
                        JournalEntry::STATUS_PENDING => 'Pending approval',
                        JournalEntry::STATUS_APPROVED => 'Approved',
                        JournalEntry::STATUS_REJECTED => 'Rejected',
                        JournalEntry::STATUS_POSTED => 'Posted',
                    ])
                    ->multiple()
                    // Defaults to posted. An unfiltered finder mixes drafts and
                    // rejected entries in with the books, and a total under the
                    // column that includes them is not a figure anybody should be
                    // reading — the filter says which, and it can be cleared.
                    ->default([JournalEntry::STATUS_POSTED])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['values'] ?? [],
                        fn (Builder $q, array $values): Builder => $q->whereHas(
                            'journalEntry',
                            fn (Builder $inner): Builder => $inner->whereIn('status', $values),
                        ),
                    )),

                SelectFilter::make('side')
                    ->label('Side')
                    ->options(['debit' => 'Debits only', 'credit' => 'Credits only'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'debit' => $query->where('debit_amount', '>', 0),
                        'credit' => $query->where('credit_amount', '>', 0),
                        default => $query,
                    }),
            ])
            ->filtersFormColumns(2)
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing matches')
            ->emptyStateDescription('Widen the dates, clear the status filter, or check the account.')
            ->emptyStateIcon('heroicon-o-magnifying-glass');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('find-transactions', 'Find Transactions: Help'),

            Action::make('register')
                ->label('Account Register')
                ->icon('heroicon-o-book-open')
                ->color('gray')
                ->url(fn (): string => AccountRegister::getUrl())
                ->visible(fn (): bool => AccountRegister::canAccess()),
        ];
    }
}
