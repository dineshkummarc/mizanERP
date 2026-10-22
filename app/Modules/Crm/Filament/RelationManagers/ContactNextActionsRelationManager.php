<?php

namespace App\Modules\Crm\Filament\RelationManagers;

use App\Modules\Crm\Filament\Resources\NextActions\NextActionResource;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What happens next with this customer — `docs/bexio-gap-plan.md` Phase 2, item 4.
 *
 * bexio's "insert reminders so follow-ups are not overlooked", on the table that already held them.
 * The fields, the due-date colouring and the Done / Snooze actions are NextActionResource's own, so
 * the customer's tab and the worked list behave identically. Contributed from CRM's provider.
 */
class ContactNextActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'nextActions';

    protected static ?string $title = 'Next actions';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return modules()->enabled('crm') && (auth()->user()?->can('OpportunityView') ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(NextActionResource::fields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                NextActionResource::dueColumn(),
                TextColumn::make('assignee.display_label')->label('Whose')->placeholder('—'),
                TextColumn::make('completed_at')->label('Done')->date('d M Y')->placeholder('—')->sortable(),
            ])
            ->defaultSort('due_on')
            ->headerActions([
                CreateAction::make()->label('Add a follow-up')->icon('heroicon-o-bell-alert'),
            ])
            ->recordActions([
                NextActionResource::completeAction(),
                NextActionResource::snoozeAction(),
                EditAction::make(),
            ]);
    }
}
