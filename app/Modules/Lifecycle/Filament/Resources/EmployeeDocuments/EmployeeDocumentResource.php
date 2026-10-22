<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\CreateEmployeeDocument;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\EditEmployeeDocument;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\ListEmployeeDocuments;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use App\Support\LandlordUserColumn;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeDocumentResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = EmployeeDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Employee document';

    protected static ?int $navigationSort = 61;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** Expired, or lapsing inside the tightest warning threshold. */
    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()
            ->expiring()
            ->whereDate('expires_on', '<=', now()->addDays(30)->toDateString())
            ->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required(),

            Select::make('kind')
                ->options(array_combine(EmployeeDocument::KINDS, array_map('ucfirst', EmployeeDocument::KINDS)))
                ->live()
                ->required(),

            TextInput::make('number')
                ->label(fn (Get $get): string => $get('kind') === EmployeeDocument::KIND_INSURANCE ? 'Policy number' : 'Number')
                ->maxLength(255),

            // A policy's own three facts — docs/bexio-gap-plan.md Phase 5, item 19. Its term and its scan
            // are the fields every document has; its expiry is watched like every other document's.
            TextInput::make('insurer')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $get('kind') === EmployeeDocument::KIND_INSURANCE),

            TextInput::make('cover_amount')
                ->label('Sum insured')
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('kind') === EmployeeDocument::KIND_INSURANCE),

            TextInput::make('premium')
                ->label('Annual premium')
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('kind') === EmployeeDocument::KIND_INSURANCE)
                ->helperText('What the company pays, in the base currency.'),

            DatePicker::make('issued_on')
                ->label(fn (Get $get): string => $get('kind') === EmployeeDocument::KIND_INSURANCE ? 'Cover from' : 'Issued on')
                ->native(false),

            DatePicker::make('expires_on')
                ->native(false)
                ->helperText('Leave blank for a document that never lapses — a degree, a CNIC copy. Warnings are sent once at 60, 30 and 7 days, not every day.'),

            FileUpload::make('file_path')
                ->label('Scan')
                ->disk('public')
                ->directory('employee-documents')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                ->maxSize(8192)
                ->helperText('Among the most sensitive records here. Served only to somebody signed in with access to this company.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('kind')->badge()->color('gray')->sortable(),

                TextColumn::make('number')->placeholder('—')->searchable()->toggleable(),

                TextColumn::make('insurer')->placeholder('—')->toggleable(),

                TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date('d M Y')
                    ->placeholder('never')
                    // "Expired 40 days ago" and "expires in 40 days" are different
                    // problems, and the colour has to say which.
                    ->color(fn (EmployeeDocument $record): string => match (true) {
                        $record->expires_on === null => 'gray',
                        $record->hasExpired() => 'danger',
                        ($record->daysUntilExpiry() ?? 999) <= 30 => 'warning',
                        default => 'gray',
                    })
                    ->description(function (EmployeeDocument $record): ?string {
                        $days = $record->daysUntilExpiry();

                        return match (true) {
                            $days === null => null,
                            $days < 0 => 'lapsed '.abs($days).' day(s) ago',
                            $days <= 60 => "in {$days} day(s)",
                            default => null,
                        };
                    })
                    ->sortable(),
            ])
            ->defaultSort('expires_on')
            ->filters([
                SelectFilter::make('kind')
                    ->options(array_combine(EmployeeDocument::KINDS, array_map('ucfirst', EmployeeDocument::KINDS))),

                Filter::make('expiring')
                    ->label('Lapsing or lapsed')
                    ->query(fn (Builder $query): Builder => $query->expiring()
                        ->whereDate('expires_on', '<=', now()->addDays(60)->toDateString())),
            ])
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeDocuments::route('/'),
            'create' => CreateEmployeeDocument::route('/create'),
            'edit' => EditEmployeeDocument::route('/{record}/edit'),
        ];
    }
}
