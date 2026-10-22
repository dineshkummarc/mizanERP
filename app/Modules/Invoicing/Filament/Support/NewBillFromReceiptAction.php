<?php

namespace App\Modules\Invoicing\Filament\Support;

use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Services\ReceiptReader;
use App\Support\Ai\ModelUnavailable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * "New bill from receipt" on the invoice list — `docs/bexio-gap-plan.md` Phase 4, item 14.
 *
 * Offered only when the command bot is switched on; the driver decides whether it can read a document
 * (the local pattern parser cannot, and says so). The upload goes straight into the attachments
 * directory, so a receipt that could not be read is still there to attach to a bill keyed by hand.
 */
class NewBillFromReceiptAction
{
    public static function make(): Action
    {
        return Action::make('newBillFromReceipt')
            ->label('New bill from receipt')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->visible(fn (): bool => (bool) config('ai.enabled')
                && (auth()->user()?->can('InvoiceCreate') ?? false))
            ->modalHeading('Read a receipt into a draft bill')
            ->modalDescription('Photograph or upload the receipt. The supplier, date, total, tax and a suggested '
                .'account are read off it into a DRAFT bill for you to check and issue — nothing posts until you '
                .'do. A supplier not on file is created and flagged in the memo.')
            ->modalSubmitActionLabel('Read it')
            ->schema([
                FileUpload::make('receipt')
                    ->label('Receipt')
                    ->disk('public')
                    ->directory('invoice-attachments')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'])
                    ->maxSize(5120)
                    ->required()
                    ->helperText('A photo or a PDF, up to 5 MB. It stays attached to the bill.'),
            ])
            ->action(function (array $data) {
                $path = is_array($data['receipt']) ? reset($data['receipt']) : $data['receipt'];
                $reader = app(ReceiptReader::class);

                try {
                    $bill = $reader->draft($reader->read((string) $path), (string) $path);
                } catch (ModelUnavailable|InvalidArgumentException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Could not read the receipt')
                        ->body($e->getMessage().' The file is kept under invoice attachments.')
                        ->send();

                    return null;
                }

                Notification::make()
                    ->success()
                    ->title("Draft {$bill->invoice_number} read from the receipt")
                    ->body('Check the supplier, the amounts and the account, then issue it.')
                    ->send();

                return redirect(InvoiceResource::getUrl('edit', ['record' => $bill]));
            });
    }
}
