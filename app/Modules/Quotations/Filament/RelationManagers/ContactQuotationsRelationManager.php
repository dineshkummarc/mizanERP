<?php

namespace App\Modules\Quotations\Filament\RelationManagers;

use App\Modules\Quotations\Filament\Resources\Quotations\QuotationResource;
use App\Modules\Quotations\Models\Quotation;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Everything quoted to this customer — `docs/bexio-gap-plan.md` Phase 2, item 4.
 *
 * `quotations.contact_id` existed and no screen read it from the customer's side. Read-only: a quote
 * is worked from its own list, and this tab opens it there. Contributed to the Contact screen from
 * this module's provider, since Quotations requires Invoicing and not the other way round.
 */
class ContactQuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotes';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return modules()->enabled('quotations') && (auth()->user()?->can('QuotationView') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->sortable()
                    ->description(fn (Quotation $record): ?string => $record->version > 1
                        ? "version {$record->version}"
                        : null),
                TextColumn::make('issue_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('valid_until')->label('Valid to')->date('d M Y')->placeholder('—'),
                TextColumn::make('total')->money('PKR')->alignEnd()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Quotation::STATUS_ACCEPTED => 'success',
                        Quotation::STATUS_SENT => 'info',
                        Quotation::STATUS_DECLINED, Quotation::STATUS_EXPIRED => 'danger',
                        Quotation::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Quotation $record): ?string => $record->invoice_id ? 'invoiced' : null),
            ])
            ->defaultSort('issue_date', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Quotation $record): string => QuotationResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
