<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\RelationManagers;

use App\Modules\Invoicing\Filament\Support\LinePricing;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\TaxRate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->label('Product')
                ->relationship('product', 'name')
                ->searchable()
                ->preload()
                ->nullable()
                ->live()
                // The list price, less this customer's standing discount, lands on the line — Phase 2.
                ->afterStateUpdated(fn ($state, callable $get, callable $set, RelationManager $livewire) => LinePricing::applyProduct(
                    $livewire->getOwnerRecord(),
                    $state,
                    $get,
                    $set,
                ))
                ->helperText('Optional. Its list price, less the customer\'s standing discount, fills the unit price.'),

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
                ->minValue(0)
                ->helperText('Quantity × unit price, filled as you type; issuing checks it.'),

            Select::make('account_id')
                ->label('Account Override')
                ->relationship('account', 'name')
                ->searchable()
                ->preload()
                ->nullable()
                ->helperText('Optional GL account override'),

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

            // The rate, not the tax. What it works out to is computed when the
            // invoice is issued, from whether that invoice is inclusive — so a
            // figure typed here would be overwritten and is not offered.
            Select::make('tax_rate_id')
                ->label('Tax')
                ->options(fn (): array => TaxRate::active()
                    ->orderByDesc('is_default')
                    ->orderByDesc('rate')
                    ->get()
                    ->mapWithKeys(fn (TaxRate $rate): array => [$rate->id => $rate->label()])
                    ->all())
                // This party's rate before the company's — §4 item 9. `getOwnerRecord()` is the invoice, so
                // a line added to an export client's invoice starts zero-rated without anybody choosing.
                ->default(fn (RelationManager $livewire): ?int => $livewire->getOwnerRecord()->contact?->default_tax_rate_id
                    ?? TaxRate::active()->where('is_default', true)->value('id'))
                ->searchable()
                ->nullable()
                ->helperText('Leave empty for a line that carries no tax.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('taxRate.name')
                    ->label('Tax')
                    ->placeholder('—')
                    ->description(fn ($record): ?string => (float) $record->tax_amount > 0
                        ? number_format((float) $record->tax_amount, 2)
                        : null),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->toggleable(),

                TextColumn::make('description')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->numeric(),

                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('PKR'),

                TextColumn::make('line_total')
                    ->label('Line Total')
                    ->money('PKR'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('InvoiceUpdate') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Model $record): bool => self::canModify($record)),
                DeleteAction::make()
                    ->visible(fn (Model $record): bool => self::canModify($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function canModify(Model $record): bool
    {
        $invoice = $record->invoice;

        return ($invoice instanceof Invoice && $invoice->isDraft())
            && (auth()->user()?->can('InvoiceUpdate') ?? false);
    }
}
