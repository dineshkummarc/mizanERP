<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Services\CorporateReturnPack as PackService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
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
 * The company's return, assembled — and the worksheet that turns accounting
 * profit into taxable income, kept beside the figures it changes.
 *
 * The statement below the form always computes from what the form shows, saved
 * or not; Save is what makes a worksheet survive the session. Business companies
 * only: a personal account has its own return pack, and this page's rates and
 * s.113 arithmetic would be wrong for one person's slabs.
 */
class CorporateReturnPack extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.corporate-return-pack';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Corporate Return Pack';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // The mirror of the personal pack's guard, for the mirrored reason — plus
        // the slab-taxed businesses (sole proprietor, AOP), which file on the
        // individual/AOP slabs and open that pack instead, not this corporate one.
        $company = Filament::getTenant() ?? Company::current();

        if (($company?->isPersonal() ?? true) || ($company?->isSlabTaxedBusiness() ?? false)) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
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
                    // Each year keeps its own worksheet: switching years swaps the
                    // stored one in rather than carrying edits across.
                    ->afterStateUpdated(function ($state): void {
                        if ($state) {
                            $this->form->fill([
                                'fiscal_year_id' => $state,
                                ...app(PackService::class)->worksheet((int) $state),
                            ]);
                        }
                    })
                    ->helperText('July to June; FBR names it for the year it ends in.'),

                TextInput::make('tax_rate')
                    ->label('Corporate rate %')
                    ->numeric()
                    ->live(onBlur: true)
                    ->helperText('The Finance Act moves this; a small company\'s rate differs.'),

                TextInput::make('minimum_tax_rate')
                    ->label('Minimum tax rate % (s.113, on turnover)')
                    ->numeric()
                    ->live(onBlur: true),

                TextInput::make('brought_forward_loss')
                    ->label('Brought-forward loss')
                    ->numeric()
                    ->live(onBlur: true)
                    ->helperText('Prior-year business loss to set against this year\'s taxable income; reduces it, not below zero.'),

                TextInput::make('super_tax')
                    ->label('Super tax (s.4C), amount')
                    ->numeric()
                    ->live(onBlur: true)
                    ->helperText('An amount, not a rate — its slabs move yearly. Your practitioner computes it; it adds on top of the tax due.'),

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
                        .'expenses, exempt income. Signed amounts — positive increases taxable income.'),
            ])
            ->statePath('data')
            ->columns(3);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('corporate-return-pack', 'Corporate Return Pack: Help'),
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

                    $pdf = Pdf::view('pdfs.corporate-return-pack', ['pack' => $pack])
                        ->format('a4')
                        ->name('corporate-return-pack-'.$pack['year']->name.'.pdf');

                    // raw(), not the response's content — see the payslip download
                    // for the 0-byte PDF this avoids.
                    return response()->streamDownload(
                        fn () => print ($pdf->raw()),
                        $pdf->getName(),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    /**
     * Computed from the form as it stands — the reader must never study a
     * statement the visible worksheet does not produce.
     *
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
