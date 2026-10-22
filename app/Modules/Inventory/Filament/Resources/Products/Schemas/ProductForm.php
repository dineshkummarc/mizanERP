<?php

namespace App\Modules\Inventory\Filament\Resources\Products\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Inventory\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(50)
                    ->unique(table: Product::class, column: 'sku', ignoreRecord: true),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Textarea::make('description')
                    ->nullable(),

                TextInput::make('unit')
                    ->required()
                    ->maxLength(20),

                TextInput::make('list_price')
                    ->label('List price')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->nullable()
                    ->helperText('What a quote or invoice line starts at when this product is chosen, less the customer\'s standing discount. Leave blank for something only ever bought.'),

                Select::make('valuation_method')
                    ->label('Valuation Method')
                    ->options([
                        'fifo' => 'FIFO',
                        'lifo' => 'LIFO',
                        'average' => 'Average Cost',
                    ])
                    ->default('fifo')
                    ->required()
                    ->helperText('How cost of goods sold is calculated'),

                TextInput::make('reorder_level')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                Select::make('inventory_account_id')
                    ->label('Inventory Account')
                    ->relationship('inventoryAccount', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Defaults to 1300'),

                Select::make('cogs_account_id')
                    ->label('COGS Account')
                    ->relationship('cogsAccount', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Defaults to 5050'),

                Select::make('revenue_account_id')
                    ->label('Revenue Account')
                    ->relationship('revenueAccount', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Defaults to 4200'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                ...CustomFieldsSchema::form(Product::class),
            ]);
    }
}
