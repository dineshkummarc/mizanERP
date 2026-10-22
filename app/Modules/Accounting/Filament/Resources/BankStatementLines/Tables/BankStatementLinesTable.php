<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatementLines\Tables;

use App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Support\ResourceContributions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class BankStatementLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bankStatement.id')
                    ->label('Statement')
                    ->sortable(),

                TextColumn::make('transaction_date')
                    ->label('Transaction Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('description')
                    ->searchable(),

                TextColumn::make('reference')
                    ->searchable(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('match_status')
                    ->label('Match Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'unmatched' => 'danger',
                        'auto_matched' => 'success',
                        'manually_matched' => 'success',
                        'excluded' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('matchedLine.id')
                    ->label('Matched Ledger Line')
                    ->placeholder('—'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                // "Record receipt", offered by Invoicing — docs/bexio-gap-plan.md Phase 3. Absent without it.
                ...ResourceContributions::recordActionsFor(BankStatementLineResource::class),
                ...self::lineActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::lineBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record reconciliation actions — parity with Nova Match / Unmatch / Exclude.
     *
     * @return array<Action>
     */
    protected static function lineActions(): array
    {
        return [
            Action::make('match')
                ->label('Match')
                ->icon('heroicon-o-link')
                ->visible(fn (BankStatementLine $record): bool => auth()->user()?->can('match', $record) ?? false)
                ->schema(self::matchFields())
                ->action(fn (array $data, BankStatementLine $record) => self::runMatch($record, $data['ledger_line_id'])),

            Action::make('unmatch')
                ->label('Unmatch')
                ->icon('heroicon-o-link-slash')
                ->visible(fn (BankStatementLine $record): bool => auth()->user()?->can('match', $record) ?? false)
                ->action(fn (BankStatementLine $record) => self::run(fn (BankReconciliationService $s) => $s->unmatch($record), 'Statement line unmatched.')),

            Action::make('exclude')
                ->label('Exclude')
                ->icon('heroicon-o-no-symbol')
                ->requiresConfirmation()
                ->modalDescription('Exclude this line from reconciliation (e.g. a bank fee with no ledger entry)?')
                ->visible(fn (BankStatementLine $record): bool => auth()->user()?->can('match', $record) ?? false)
                ->action(fn (BankStatementLine $record) => self::run(fn (BankReconciliationService $s) => $s->exclude($record), 'Statement line excluded.')),
        ];
    }

    /**
     * Bulk equivalents — run over each selected line.
     *
     * @return array<BulkAction>
     */
    protected static function lineBulkActions(): array
    {
        return [
            BulkAction::make('matchBulk')
                ->label('Match')
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => auth()->user()?->can('BankStatementMatch') ?? false)
                ->schema(self::matchFields())
                ->action(fn (array $data, Collection $records) => self::runMatchBulk($records, $data['ledger_line_id'])),

            BulkAction::make('unmatchBulk')
                ->label('Unmatch')
                ->icon('heroicon-o-link-slash')
                ->visible(fn (): bool => auth()->user()?->can('BankStatementMatch') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (BankReconciliationService $s, BankStatementLine $l) => $s->unmatch($l), 'unmatched')),

            BulkAction::make('excludeBulk')
                ->label('Exclude')
                ->icon('heroicon-o-no-symbol')
                ->requiresConfirmation()
                ->modalDescription('Exclude these lines from reconciliation (e.g. bank fees with no ledger entry)?')
                ->visible(fn (): bool => auth()->user()?->can('BankStatementMatch') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (BankReconciliationService $s, BankStatementLine $l) => $s->exclude($l), 'excluded')),
        ];
    }

    /**
     * @return array<Select>
     */
    protected static function matchFields(): array
    {
        return [
            Select::make('ledger_line_id')
                ->label('Ledger Line')
                ->options(fn (): array => self::ledgerLineOptions())
                ->searchable()
                ->required()
                ->helperText('Choose the unreconciled posted ledger line this statement line corresponds to.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function ledgerLineOptions(): array
    {
        return JournalEntryLine::query()
            ->whereNull('reconciled_at')
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true))
            ->whereDoesntHave('bankStatementLine')
            ->with('journalEntry:id,entry_number,entry_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (JournalEntryLine $l) => [
                $l->id => sprintf(
                    '%s · %s · %s',
                    $l->journalEntry->entry_number,
                    $l->journalEntry->entry_date->toDateString(),
                    number_format($l->signed_amount, 2)
                ),
            ])->all();
    }

    protected static function runMatch(BankStatementLine $record, $ledgerLineId): void
    {
        $ledgerLine = JournalEntryLine::find($ledgerLineId);

        if (! $ledgerLine) {
            Notification::make()->title('Selected ledger line not found.')->danger()->send();

            return;
        }

        try {
            app(BankReconciliationService::class)->match($record, $ledgerLine);
            Notification::make()->title('Statement line matched.')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runMatchBulk(Collection $records, $ledgerLineId): void
    {
        $ledgerLine = JournalEntryLine::find($ledgerLineId);

        if (! $ledgerLine) {
            Notification::make()->title('Selected ledger line not found.')->danger()->send();

            return;
        }

        $service = app(BankReconciliationService::class);

        try {
            foreach ($records as $record) {
                $service->match($record, $ledgerLine);
            }
            Notification::make()->title('Statement line matched.')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function run(callable $op, string $message): void
    {
        try {
            $op(app(BankReconciliationService::class));
            Notification::make()->title($message)->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $verb): void
    {
        $service = app(BankReconciliationService::class);
        $done = 0;

        try {
            foreach ($records as $record) {
                $op($service, $record);
                $done++;
            }
            Notification::make()->title("{$done} statement line(s) {$verb}.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
