<?php

namespace App\Modules\Mpr\Filament\Resources\MPRs;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Mpr\Filament\Resources\MPRs\Pages\CreateMPR;
use App\Modules\Mpr\Filament\Resources\MPRs\Pages\EditMPR;
use App\Modules\Mpr\Filament\Resources\MPRs\Pages\ListMPRS;
use App\Modules\Mpr\Filament\Resources\MPRs\Schemas\MPRForm;
use App\Modules\Mpr\Filament\Resources\MPRs\Tables\MPRSTable;
use App\Modules\Mpr\Models\MPR;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MPRResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = MPR::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'MPR';

    protected static ?string $pluralModelLabel = 'MPR';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'user.name'];
    }

    /**
     * Privileged roles see all MPRs; everyone else sees their own plus those of
     * their reporting downline. MPR keys on user_id, so filter by user ids.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('user_id', static::accessibleUserIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return MPRForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MPRSTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMPRS::route('/'),
            'create' => CreateMPR::route('/create'),
            'edit' => EditMPR::route('/{record}/edit'),
        ];
    }
}
