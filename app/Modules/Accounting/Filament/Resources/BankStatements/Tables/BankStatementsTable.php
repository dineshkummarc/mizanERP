<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatements\Tables;

use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Support\StatementCsv;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BankStatementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label('Bank Account')
                    ->sortable(),

                TextColumn::make('statement_date')
                    ->label('Statement Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('opening_balance')
                    ->label('Opening Balance')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('closing_balance')
                    ->label('Closing Balance')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'info',
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (BankStatement $record): string => ($record->lines_count ?? 0)
                        ? "{$record->matched_count} / {$record->lines_count} matched"
                        : '—'),

                TextColumn::make('completedBy.name')
                    ->label('Completed By')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('completed_at')
                    ->label('Completed At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ...self::reconciliationActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ...self::reconciliationBulkActions(),
                ]),
            ]);
    }

    /**
     * Per-record actions — parity with Nova ImportStatementLines / AutoMatchStatement / CompleteReconciliation.
     *
     * @return array<Action>
     */
    protected static function reconciliationActions(): array
    {
        return [
            /**
             * The bank's own CSV, uploaded, with its columns named — docs/bexio-gap-plan.md Phase 3,
             * item 12. The mapping is saved on the bank account chosen, so the next statement from that
             * bank is upload-and-done; picking the account fills the mapping back in.
             */
            Action::make('importLines')
                ->label('Import statement (CSV)')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (BankStatement $record): bool => auth()->user()?->can('import', $record) ?? false)
                ->modalDescription('Upload the CSV the bank gives you and say which of its columns hold what. Each '
                    .'line becomes a statement line, money in positive and money out negative.')
                ->modalSubmitActionLabel('Import')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(4096)
                        ->disk('public')
                        ->directory('statement-imports')
                        ->required(),

                    Select::make('company_bank_account_id')
                        ->label('Bank account')
                        ->options(fn (): array => CompanyBankAccount::query()->where('is_active', true)->pluck('title', 'id')->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $mapping = CompanyBankAccount::query()->find($state)?->statement_mapping ?? [];

                            foreach (StatementCsv::FIELDS as $field) {
                                $set("mapping.{$field}", $mapping[$field] ?? null);
                            }
                        })
                        ->helperText('Whose export this is. Its saved column mapping fills in below; what you import with is saved back.'),

                    Fieldset::make('Columns, by their header in the file')
                        ->schema([
                            TextInput::make('mapping.date')->label('Date')->required()->placeholder('Txn Date'),
                            TextInput::make('mapping.date_format')->label('Date format')->placeholder('d/m/Y')
                                ->helperText('Only if the dates are not recognised on their own.'),
                            TextInput::make('mapping.description')->label('Narration')->placeholder('Particulars'),
                            TextInput::make('mapping.reference')->label('Reference')->placeholder('Ref No'),
                            TextInput::make('mapping.amount')->label('Signed amount')->placeholder('Amount')
                                ->helperText('One column, negative for money out — or leave this and use the two below.'),
                            TextInput::make('mapping.credit')->label('Money in (credit)')->placeholder('Credit'),
                            TextInput::make('mapping.debit')->label('Money out (debit)')->placeholder('Debit'),
                        ])
                        ->columns(2),
                ])
                ->action(fn (array $data, BankStatement $record) => self::run(function (BankReconciliationService $s) use ($data, $record) {
                    $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    $csv = Storage::disk('public')->get($path);
                    $mapping = array_filter(array_map('trim', $data['mapping'] ?? []), fn (string $v): bool => $v !== '');

                    $rows = StatementCsv::rows((string) $csv, $mapping);

                    if ($rows === []) {
                        throw new \InvalidArgumentException('Nothing below the header row.');
                    }

                    $s->import($rows, $record);

                    CompanyBankAccount::query()->find($data['company_bank_account_id'])?->update(['statement_mapping' => $mapping]);
                    Storage::disk('public')->delete($path);

                    return count($rows).' line(s) imported; the column mapping is saved on the bank account.';
                }, 'Import')),

            Action::make('autoMatch')
                ->label('Auto-Match')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->modalDescription('Auto-match unmatched statement lines against the ledger (exact amount + date within 3 days, or amount + reference)?')
                ->visible(fn (BankStatement $record): bool => auth()->user()?->can('match', $record) ?? false)
                ->action(fn (BankStatement $record) => self::run(fn (BankReconciliationService $s) => 'Auto-matched '.$s->autoMatch($record).' line(s).', 'Auto-Match')),

            Action::make('completeReconciliation')
                ->label('Complete Reconciliation')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->modalDescription('Complete this reconciliation? All lines must be matched or excluded and the closing balance must equal the ledger balance. This locks the statement.')
                ->visible(fn (BankStatement $record): bool => auth()->user()?->can('complete', $record) ?? false)
                ->action(fn (BankStatement $record) => self::run(function (BankReconciliationService $s) use ($record) {
                    $s->complete($record, auth()->user());

                    return 'Reconciliation completed.';
                }, 'Complete Reconciliation')),
        ];
    }

    /**
     * Bulk equivalents — run over each selected statement.
     *
     * @return array<BulkAction>
     */
    protected static function reconciliationBulkActions(): array
    {
        return [
            BulkAction::make('importLinesBulk')
                ->label('Import Lines (CSV)')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->can('BankStatementImport') ?? false)
                ->schema([
                    Textarea::make('csv')
                        ->label('CSV')
                        ->required()
                        ->helperText('One row per line: transaction_date,description,reference,amount (amount signed; negative for money out).'),
                ])
                ->action(fn (array $data, Collection $records) => self::runBulk($records, function (BankReconciliationService $s, BankStatement $r) use ($data) {
                    $rows = self::parseCsv($data['csv']);
                    if ($rows === []) {
                        throw new \InvalidArgumentException('No valid rows found.');
                    }
                    $s->import($rows, $r);
                }, 'Import Lines')),

            BulkAction::make('autoMatchBulk')
                ->label('Auto-Match')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('BankStatementMatch') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (BankReconciliationService $s, BankStatement $r) => $s->autoMatch($r), 'Auto-Match')),

            BulkAction::make('completeReconciliationBulk')
                ->label('Complete Reconciliation')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()?->can('BankStatementComplete') ?? false)
                ->action(fn (Collection $records) => self::runBulk($records, fn (BankReconciliationService $s, BankStatement $r) => $s->complete($r, auth()->user()), 'Complete Reconciliation')),
        ];
    }

    /**
     * Run a single-statement operation, surfacing service validation errors as notifications.
     * The callable returns a success message.
     */
    protected static function run(callable $op, string $label): void
    {
        try {
            $message = $op(app(BankReconciliationService::class));
            Notification::make()->title($message ?: "{$label}: processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected static function runBulk(Collection $records, callable $op, string $label): void
    {
        $service = app(BankReconciliationService::class);
        $done = 0;
        try {
            foreach ($records as $record) {
                $op($service, $record);
                $done++;
            }
            Notification::make()->title("{$label}: {$done} statement(s) processed.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Exception $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return array<int, array<string, ?string>>
     */
    protected static function parseCsv(string $csv): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) as $raw) {
            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            $cols = array_map('trim', str_getcsv($raw));

            if (strtolower($cols[0] ?? '') === 'transaction_date') {
                continue; // header row
            }

            $rows[] = [
                'transaction_date' => $cols[0] ?? null,
                'description' => $cols[1] ?? null,
                'reference' => $cols[2] ?? null,
                'amount' => $cols[3] ?? null,
            ];
        }

        return $rows;
    }
}
