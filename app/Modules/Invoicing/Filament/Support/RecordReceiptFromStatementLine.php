<?php

namespace App\Modules\Invoicing\Filament\Support;

use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\StatementReceipts;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * "Record receipt" on a bank statement line — `docs/bexio-gap-plan.md` Phase 3, item 12.
 *
 * Contributed to Accounting's statement-line table from Invoicing's provider, because the table may not
 * name an invoice (ModuleBoundaryTest). Visible only on an unmatched line of money coming in, to
 * somebody who may record payments.
 */
class RecordReceiptFromStatementLine
{
    public static function make(): Action
    {
        return Action::make('recordReceipt')
            ->label('Record receipt')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (BankStatementLine $record): bool => $record->match_status === BankStatementLine::STATUS_UNMATCHED
                && (float) $record->amount > 0
                && (auth()->user()?->can('InvoicePay') ?? false))
            ->modalHeading('Record this receipt against an invoice')
            ->modalDescription(fn (BankStatementLine $record): string => number_format((float) $record->amount, 2)
                .' arrived on '.$record->transaction_date->format('d M Y')
                .(filled($record->description) ? " — {$record->description}" : '')
                .'. The invoice is settled, the receipt is posted to this statement\'s account, and the line is '
                .'matched to it, in one step. A transfer covering several invoices is for the Record a receipt screen.')
            ->modalSubmitActionLabel('Record and match')
            ->schema([
                Select::make('invoice_id')
                    ->label('Invoice')
                    ->options(fn (): array => app(StatementReceipts::class)->openInvoices()
                        ->mapWithKeys(fn (Invoice $invoice): array => [$invoice->getKey() => sprintf(
                            '%s · %s · %s outstanding',
                            $invoice->invoice_number,
                            $invoice->contact?->name ?? '—',
                            number_format($invoice->outstanding(), 2),
                        )])
                        ->all())
                    ->default(fn (BankStatementLine $record): ?int => app(StatementReceipts::class)->suggest($record)?->getKey())
                    ->searchable()
                    ->required()
                    ->helperText('Suggested: the one open invoice owed exactly this amount, or the one whose number is in the narration.'),
            ])
            ->action(function (array $data, BankStatementLine $record): void {
                try {
                    app(StatementReceipts::class)->record($record, Invoice::query()->findOrFail($data['invoice_id']));
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Receipt recorded and the line matched.')->send();
            });
    }
}
