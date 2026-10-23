<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\CreateContact;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers\DocumentsRelationManager;
use App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers\InvoicesRelationManager;
use App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers\PeopleRelationManager;
use App\Modules\Invoicing\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Modules\Invoicing\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Modules\Invoicing\Models\Contact;
use App\Support\ResourceContributions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContactResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'ntn'];
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PeopleRelationManager::class,
            DocumentsRelationManager::class,
            InvoicesRelationManager::class,
            // Quotes, calls and next actions — offered by Quotations and CRM from their own providers,
            // because this module may not name either (docs/bexio-gap-plan.md Phase 2, item 4).
            ...ResourceContributions::relationManagersFor(static::class),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
