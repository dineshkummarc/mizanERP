<?php

namespace App\Modules\PersonalFinance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Services\SoleProprietorReturnPack as PackService;
use App\Support\Pdf\Pdf;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The slab-business return: business profit taxed on the individual/AOP slab
 * schedule rather than a company rate. Serves both entities on that schedule —
 * a sole proprietor and an AOP/partnership — since the arithmetic is identical;
 * only the title and one filing footnote differ. A company proper opens the
 * Corporate pack instead, which excludes both of these entities.
 */
class SoleProprietorReturnPack extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.sole-proprietor-return-pack';

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // A business taxed on the individual/AOP slabs — a sole proprietor or an
        // AOP. Not a personal account (that is the ordinary Return Pack), not a
        // company (that is the Corporate one).
        $company = Filament::getTenant() ?? Company::current();

        if (! $company || $company->isPersonal() || ! $company->isSlabTaxedBusiness()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }

    public function getTitle(): string
    {
        return $this->entityLabel().' Return Pack';
    }

    public static function getNavigationLabel(): string
    {
        $company = Filament::getTenant() ?? Company::current();

        return ($company?->slabBusinessLabel() ?? 'Sole Proprietor').' Return Pack';
    }

    private function entityLabel(): string
    {
        return (Filament::getTenant() ?? Company::current())?->slabBusinessLabel() ?? 'Sole Proprietor';
    }

    public function mount(): void
    {
        $yearId = FiscalYear::current()?->getKey()
            ?? FiscalYear::orderByDesc('start_date')->value('id');

        $this->form->fill([
            'fiscal_year_id' => $yearId,
            ...($yearId ? app(PackService::class)->worksheet((int) $yearId) : []),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Tax year')
                    ->options(FiscalYear::orderByDesc('start_date')->pluck('name', 'id'))
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state): void {
                        if ($state) {
                            $this->form->fill([
                                'fiscal_year_id' => $state,
                                ...app(PackService::class)->worksheet((int) $state),
                            ]);
                        }
                    })
                    ->helperText('July to June; FBR names it for the year it ends in.'),

                TextInput::make('minimum_tax_rate')
                    ->label('Minimum tax rate % (s.113, on turnover)')
                    ->numeric()
                    ->live(onBlur: true),

                TextInput::make('minimum_tax_threshold')
                    ->label('Minimum tax turnover threshold')
                    ->numeric()
                    ->live(onBlur: true)
                    ->helperText('s.113 binds an individual/AOP only at or above this turnover (a company has no threshold). '
                        .'Confirm the current figure with your practitioner.'),

                Repeater::make('adjustments')
                    ->label('Tax adjustments to accounting profit')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('label')
                            ->placeholder('e.g. Accounting depreciation added back / Tax depreciation (Third Schedule)'),
                        TextInput::make('amount')
                            ->numeric()
                            ->placeholder('positive adds back, negative deducts'),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add adjustment')
                    ->live(onBlur: true)
                    ->helperText('What the ledger cannot know: tax vs accounting depreciation, inadmissible '
                        .'expenses, exempt income. Signed — positive increases taxable income.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('sole-proprietor-return-pack', 'Sole Proprietor and AOP Return Pack: Help'),
            Action::make('save')
                ->label('Save worksheet')
                ->icon('heroicon-o-check')
                ->action(function (): void {
                    if ($yearId = $this->data['fiscal_year_id'] ?? null) {
                        app(PackService::class)->saveWorksheet((int) $yearId, $this->data);
                        Notification::make()->success()->title('Worksheet saved for this year.')->send();
                    }
                }),

            Action::make('download')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    $pack = $this->getPack();

                    if (! $pack) {
                        return null;
                    }

                    $pdf = Pdf::view('pdfs.sole-proprietor-return-pack', ['pack' => $pack])
                        ->format('a4')
                        ->name('sole-proprietor-return-pack-'.$pack['year']->name.'.pdf');

                    return response()->streamDownload(
                        fn () => print ($pdf->raw()),
                        $pdf->getName(),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPack(): ?array
    {
        $yearId = $this->data['fiscal_year_id'] ?? null;

        return $yearId
            ? app(PackService::class)->build((int) $yearId, $this->data)
            : null;
    }
}
