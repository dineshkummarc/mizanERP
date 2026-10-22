<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Support\NewBillFromReceiptAction;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('invoices', 'Invoices: Help'),
            // A photographed receipt into a draft bill — docs/bexio-gap-plan.md Phase 4. Absent until
            // the command bot is switched on.
            NewBillFromReceiptAction::make(),
            CreateAction::make(),
            $this->saveViewAction(),
        ];
    }

    /**
     * Sales and bills on their own tabs — docs/bexio-gap-plan.md Phase 3, item 11.
     *
     * One list, because `kind` is a column and not a table; but somebody paying suppliers wants the
     * bills and their debit notes without the sales in between. A note sits with the document it corrects.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'sales' => Tab::make('Sales')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])),
            'bills' => Tab::make('Bills')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('kind', [Invoice::KIND_PURCHASE, Invoice::KIND_DEBIT_NOTE])),
        ];
    }
}
