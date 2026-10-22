<?php

namespace App\Modules\Invoicing\Filament\Resources\InvoiceLines\Schemas;

use App\Modules\Invoicing\Filament\Support\LinePricing;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InvoiceLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('invoice_id')
                    ->label('Invoice')
                    ->relationship('invoice', 'invoice_number')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => LinePricing::applyProduct(
                        Invoice::query()->find($get('invoice_id')),
                        $state,
                        $get,
                        $set,
                    ))
                    ->helperText('Leave empty for service / non-product lines. A product\'s list price, less the customer\'s standing discount, fills the unit price.'),

                TextInput::make('description')
                    ->required()
                    ->maxLength(255),

                TextInput::make('quantity')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0.01)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (callable $get, callable $set) => LinePricing::fillTotal($get, $set)),

                TextInput::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (callable $get, callable $set) => LinePricing::fillTotal($get, $set)),

                TextInput::make('line_total')
                    ->label('Line Total')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0),

                // When the thing on this line is delivered — the other half of the gap plan's deferral item. An
                // annual licence billed in July is eleven months of next year's revenue, and these two dates are
                // how the invoice says so; "Defer over service period" on the issued invoice then does the rest.
                // Blank on every line that is delivered when it is billed, which is most of them.
                DatePicker::make('service_from')
                    ->label('Service from')
                    ->nullable()
                    ->helperText('Leave blank unless this line is for a period rather than a delivery.'),
                DatePicker::make('service_to')
                    ->label('Service to')
                    ->nullable()
                    ->afterOrEqual('service_from'),

                Select::make('account_id')
                    ->label('Account Override')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Posting account for non-product lines'),
            ]);
    }
}
