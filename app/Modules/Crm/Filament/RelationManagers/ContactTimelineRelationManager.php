<?php

namespace App\Modules\Crm\Filament\RelationManagers;

use App\Modules\Crm\Filament\Support\ActivityLogFields;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A customer's calls, meetings and notes — `docs/bexio-gap-plan.md` Phase 2, item 4.
 *
 * The `activities` table was polymorphic over Contact from the day it was made, and the only screen
 * that wrote one was a deal's row. This is the same table from the customer's side. Contributed to
 * the Contact screen from CRM's provider, because Invoicing may not name CRM — see
 * App\Support\ResourceContributions.
 *
 * History is not edited (ActivityPolicy): log the correction instead.
 */
class ContactTimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timeline';

    protected static ?string $title = 'Calls & notes';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return modules()->enabled('crm') && (auth()->user()?->can('OpportunityView') ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(ActivityLogFields::make());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('kind')->badge()->color('gray'),
                TextColumn::make('outcome')->placeholder('—')->wrap(),
                TextColumn::make('duration_minutes')->label('Min')->placeholder('—')->alignEnd(),
                TextColumn::make('body')->limit(80)->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('employee.display_label')->label('By')->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->headerActions([
                CreateAction::make()->label('Log a call')->icon('heroicon-o-phone'),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
