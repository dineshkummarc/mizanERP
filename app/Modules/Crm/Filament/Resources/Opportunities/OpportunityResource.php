<?php

namespace App\Modules\Crm\Filament\Resources\Opportunities;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Crm\Filament\Resources\Opportunities\Pages\CreateOpportunity;
use App\Modules\Crm\Filament\Resources\Opportunities\Pages\EditOpportunity;
use App\Modules\Crm\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Modules\Crm\Filament\Support\ActivityLogFields;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\Crm\Services\OpportunityService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * Deals.
 *
 * Two things on this screen are worth reading before changing it.
 *
 * **A deal belongs to a lead OR a customer, never both** — except once the lead has
 * converted, which is the one legitimate case. The form offers whichever the company can
 * have: a customer picker only appears with `invoicing` licensed, and a company without it
 * works entirely in leads.
 *
 * **Winning a deal posts nothing and invoices nothing.** §10 says so three times over,
 * because a CRM is where automation is most tempting. The hand-offs are separate actions
 * somebody takes deliberately.
 */
class OpportunityResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = Opportunity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Deal';

    protected static ?int $navigationSort = 11;

    /** Own deals and a manager's downline — the same BFS every employee-keyed resource uses. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (modules()->enabled('employees') && ! static::userIsPrivileged()) {
            $query->whereIn('owner_employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->open()->count());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The deal')
                ->schema([
                    TextInput::make('title')->required()->maxLength(255)->placeholder('Warehouse system, phase 1'),

                    Select::make('pipeline_id')
                        ->label('Pipeline')
                        ->options(fn (): array => Pipeline::query()->active()->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Pipeline::default()?->getKey())
                        ->required()
                        ->live(),

                    Select::make('pipeline_stage_id')
                        ->label('Stage')
                        ->options(fn (Get $get): array => $get('pipeline_id')
                            ? PipelineStage::where('pipeline_id', $get('pipeline_id'))->orderBy('sort')->pluck('name', 'id')->all()
                            : [])
                        ->default(fn (): ?int => Pipeline::default()?->firstStage()?->getKey())
                        ->required(),

                    Select::make('owner_employee_id')
                        ->label('Owner')
                        ->options(fn (): array => Employee::query()
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                            ->all())
                        ->searchable()
                        ->visible(fn (): bool => modules()->enabled('employees'))
                        ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id'))
                        ->helperText('Also decides who can see it.'),
                ])
                ->columns(2),

            Section::make('Who it is with')
                ->description('A lead or a customer, not both — unless the lead has already become that customer.')
                ->schema([
                    Select::make('lead_id')
                        ->label('Lead')
                        ->options(fn (): array => Lead::query()
                            ->orderByDesc('id')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn (Lead $lead): array => [$lead->id => $lead->display_label])
                            ->all())
                        ->searchable()
                        ->helperText('New business starts here.'),

                    // Absent entirely without Invoicing: there are no customers to pick, and
                    // §1's whole point is that CRM works without it.
                    Select::make('contact_id')
                        ->label('Customer')
                        ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->visible(fn (): bool => modules()->enabled('invoicing'))
                        ->helperText('A repeat deal against somebody who already buys from you.'),
                ])
                ->columns(2),

            Section::make('What it is worth')
                ->schema([
                    TextInput::make('amount')->numeric()->minValue(0)->default(0)->required(),

                    TextInput::make('currency_code')->label('Currency')->maxLength(3)->placeholder('PKR')
                        ->helperText('Blank means the company\'s own currency.'),

                    TextInput::make('exchange_rate')
                        ->label('Rate used')
                        ->numeric()
                        ->helperText('Stored with the deal on purpose. A forecast that read today\'s rate would rewrite last quarter every morning.'),

                    TextInput::make('probability_pct')
                        ->label('Likely to close (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->helperText('Inherited from the stage. Set it here only if you know better for this deal.'),

                    DatePicker::make('expected_close_on')->native(false)
                        ->helperText('What the forecast groups by.'),
                ])
                ->columns(2),

            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Opportunity $record): string => $record->partyLabel()),

                TextColumn::make('stage.name')
                    ->label('Stage')
                    ->badge()
                    ->color(fn (Opportunity $record): string => match (true) {
                        $record->isWon() => 'success',
                        $record->outcome === Opportunity::OUTCOME_LOST => 'danger',
                        default => 'info',
                    })
                    ->description(fn (Opportunity $record): ?string => $record->lostReason?->name)
                    ->sortable(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->alignEnd()
                    ->description(fn (Opportunity $record): ?string => (int) $record->probability_pct > 0
                        && $record->isOpen()
                            ? 'weighted '.number_format($record->weightedAmount(), 0)
                            : null)
                    ->sortable(),

                TextColumn::make('owner.display_label')
                    ->label('Owner')
                    ->placeholder('—')
                    ->visible(fn (): bool => modules()->enabled('employees'))
                    ->toggleable(),

                TextColumn::make('expected_close_on')->label('Expected')->date('d M Y')->placeholder('—')->sortable(),

                // The one column that tells somebody to do something. An open deal with
                // nothing planned is the problem §3 says to surface, and this is where.
                TextColumn::make('next_action')
                    ->label('Next')
                    ->state(fn (Opportunity $record): string => $record->isOpen()
                        ? ($record->nextActions()->open()->orderBy('due_on')->value('title') ?? 'nothing planned')
                        : '—')
                    ->color(fn (Opportunity $record): string => $record->isOpen() && ! $record->hasOpenNextAction()
                        ? 'danger'
                        : 'gray')
                    ->wrap(),
            ])
            ->defaultSort('expected_close_on')
            ->filters([
                SelectFilter::make('pipeline_id')
                    ->label('Pipeline')
                    ->options(fn (): array => Pipeline::pluck('name', 'id')->all()),

                SelectFilter::make('pipeline_stage_id')
                    ->label('Stage')
                    ->options(fn (): array => PipelineStage::orderBy('sort')->pluck('name', 'id')->all()),

                Filter::make('open')
                    ->label('Open')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->open()),

                Filter::make('unplanned')
                    ->label('Nothing planned')
                    ->query(fn (Builder $query): Builder => $query->open()
                        ->whereDoesntHave('nextActions', fn (Builder $actions) => $actions->open())),
            ])
            ->recordActions([
                Action::make('move')
                    ->label('Move')
                    ->icon('heroicon-o-arrow-right')
                    ->color('info')
                    ->visible(fn (Opportunity $record): bool => auth()->user()?->can('move', $record) ?? false)
                    ->schema([
                        Select::make('pipeline_stage_id')
                            ->label('To stage')
                            ->options(fn (Opportunity $record): array => PipelineStage::query()
                                ->where('pipeline_id', $record->pipeline_id)
                                ->orderBy('sort')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->helperText('Recorded with how long it sat where it was — moves backwards included.'),
                    ])
                    ->action(function (Opportunity $record, array $data): void {
                        try {
                            app(OpportunityService::class)->moveTo(
                                $record,
                                PipelineStage::findOrFail($data['pipeline_stage_id']),
                                auth()->user(),
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Moved.')->send();
                    }),

                Action::make('logActivity')
                    ->label('Log a call')
                    ->icon('heroicon-o-phone')
                    ->color('gray')
                    ->visible(fn (Opportunity $record): bool => auth()->user()?->can('update', $record) ?? false)
                    // The same four fields the customer's timeline tab uses — docs/bexio-gap-plan.md Phase 2.
                    ->schema(ActivityLogFields::make())
                    ->action(function (Opportunity $record, array $data): void {
                        $record->timeline()->create($data + ['occurred_at' => now()]);

                        Notification::make()->success()->title('Logged.')->send();
                    }),

                Action::make('planNext')
                    ->label('Plan the next step')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->visible(fn (Opportunity $record): bool => $record->isOpen()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255)->placeholder('Call about the pricing'),
                        DatePicker::make('due_on')->native(false)->default(now()->addWeek())->required(),
                    ])
                    ->action(function (Opportunity $record, array $data): void {
                        $record->nextActions()->create($data);

                        Notification::make()->success()->title('Planned.')->send();
                    }),

                Action::make('win')
                    ->label('Won')
                    ->icon('heroicon-o-trophy')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Records the win. It does NOT raise an invoice or post anything — those are separate, deliberate steps.')
                    ->visible(fn (Opportunity $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->action(function (Opportunity $record): void {
                        app(OpportunityService::class)->markWon($record, auth()->user());

                        Notification::make()->success()
                            ->title('Won.')
                            ->body('Raise the invoice or open the project from the deal when you are ready.')
                            ->send();
                    }),

                Action::make('lose')
                    ->label('Lost')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Opportunity $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->schema([
                        Select::make('lost_reason_id')
                            ->label('Why')
                            ->options(fn (): array => LostReason::query()->active()->orderBy('sort')->pluck('name', 'id')->all())
                            ->required()
                            ->helperText('From the list rather than free text: win rate by reason is the report worth having, and three spellings of "too expensive" produce three rates.'),
                    ])
                    ->action(function (Opportunity $record, array $data): void {
                        try {
                            app(OpportunityService::class)->markLost(
                                $record,
                                LostReason::find($data['lost_reason_id']),
                                auth()->user(),
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Recorded as lost, with the reason.')->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpportunities::route('/'),
            'create' => CreateOpportunity::route('/create'),
            'edit' => EditOpportunity::route('/{record}/edit'),
        ];
    }
}
