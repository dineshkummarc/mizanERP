<?php

namespace App\Modules\Crm\Filament\Resources\NextActions;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Crm\Filament\Resources\NextActions\Pages\CreateNextAction;
use App\Modules\Crm\Filament\Resources\NextActions\Pages\EditNextAction;
use App\Modules\Crm\Filament\Resources\NextActions\Pages\ListNextActions;
use App\Modules\Crm\Models\NextAction;
use App\Modules\Employees\Models\Employee;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What happens next.
 *
 * **The one surface in this module that changes what somebody does today** rather than
 * recording what they did. Everything else here is a register; this is a list to work.
 */
class NextActionResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = NextAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $modelLabel = 'Next action';

    protected static ?int $navigationSort = 12;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (modules()->enabled('employees') && ! static::userIsPrivileged()) {
            $query->whereIn('assignee_employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** What is due today or overdue — the number somebody should act on. */
    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->due()->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ...static::fields(),

            // Carried rather than chosen: an action is created from the deal or lead it is
            // about, and a free-text pair of morph columns on a form is how somebody ends up
            // with an action attached to nothing.
            TextInput::make('subject_type')->hidden()->dehydrated(),
            TextInput::make('subject_id')->hidden()->dehydrated(),
        ]);
    }

    /**
     * The three fields an action is made of — shared with the customer's Next actions tab
     * (docs/bexio-gap-plan.md Phase 2), which supplies the subject from the relation instead.
     *
     * @return array<int, TextInput|DatePicker|Select>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('title')->required()->maxLength(255)->placeholder('Call about the pricing'),

            DatePicker::make('due_on')->native(false)->default(now())->required()
                ->helperText('A date is enough. Most next actions are "call them Tuesday", and forcing a time makes somebody invent one.'),

            Select::make('assignee_employee_id')
                ->label('Whose')
                ->options(fn (): array => Employee::query()
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->visible(fn (): bool => modules()->enabled('employees'))
                ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),
        ];
    }

    /** The due date, coloured by urgency, naming its snooze. Shared with the customer's tab. */
    public static function dueColumn(): TextColumn
    {
        return TextColumn::make('due_on')
            ->label('Due')
            ->date('d M Y')
            ->color(fn (NextAction $record): string => match (true) {
                ! $record->isOpen() => 'gray',
                $record->effectiveDueOn()->isPast() => 'danger',
                $record->effectiveDueOn()->isToday() => 'warning',
                default => 'gray',
            })
            // The snooze is named rather than hidden: how often something has been
            // pushed is usually the more useful fact than the new date.
            ->description(fn (NextAction $record): ?string => $record->isSnoozed()
                ? 'snoozed to '.$record->snoozed_until->format('d M Y')
                : null)
            ->sortable();
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Done')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (NextAction $record): bool => $record->isOpen()
                && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (NextAction $record): void {
                $record->update(['completed_at' => now(), 'completed_by' => auth()->id()]);

                Notification::make()->success()->title('Done. What is the next one?')->send();
            });
    }

    public static function snoozeAction(): Action
    {
        return Action::make('snooze')
            ->label('Snooze')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->visible(fn (NextAction $record): bool => $record->isOpen()
                && (auth()->user()?->can('update', $record) ?? false))
            ->schema([
                DatePicker::make('snoozed_until')
                    ->label('Until')
                    ->native(false)
                    ->default(now()->addWeek())
                    ->required()
                    ->helperText('The original due date is kept, so how often this has been pushed stays visible.'),
            ])
            ->action(function (NextAction $record, array $data): void {
                $record->update(['snoozed_until' => $data['snoozed_until']]);

                Notification::make()->success()->title('Snoozed.')->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),

                static::dueColumn(),

                TextColumn::make('assignee.display_label')->label('Whose')->placeholder('—')->toggleable(),

                TextColumn::make('subject_type')
                    ->label('About')
                    ->formatStateUsing(fn (?string $state): string => match (true) {
                        str_contains((string) $state, 'Opportunity') => 'Deal',
                        str_contains((string) $state, 'Lead') => 'Lead',
                        str_contains((string) $state, 'Contact') => 'Customer',
                        default => '—',
                    })
                    ->badge()
                    ->color('gray'),

                TextColumn::make('completed_at')->label('Done')->date('d M Y')->placeholder('—')->sortable(),
            ])
            ->defaultSort('due_on')
            ->filters([
                Filter::make('due')
                    ->label('Due now')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->due()),

                Filter::make('open')
                    ->label('All open')
                    ->query(fn (Builder $query): Builder => $query->open()),
            ])
            ->recordActions([
                static::completeAction(),
                static::snoozeAction(),
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNextActions::route('/'),
            'create' => CreateNextAction::route('/create'),
            'edit' => EditNextAction::route('/{record}/edit'),
        ];
    }
}
