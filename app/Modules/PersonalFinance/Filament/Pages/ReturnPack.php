<?php

namespace App\Modules\PersonalFinance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\PersonalTaxProfile;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Services\PersonalReturnPack;
use App\Support\Pdf\Pdf;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use RuntimeException;
use UnitEnum;

/**
 * The year's return, assembled: every figure IRIS asks for, under the heading it
 * asks for it by, plus the wealth statement and its reconciliation.
 *
 * Prepares, never transmits — FBR has no filing API, so the last step is a person
 * on iris.fbr.gov.pk with this pack beside them (or a tax practitioner handed the
 * PDF). Access mirrors TaxEstimate exactly: a personal account's own screen, and
 * business books must never be read as one person's taxable income.
 */
class ReturnPack extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.personal-return-pack';

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Return Pack';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // Personal accounts only — same reasoning and same idiom as TaxEstimate.
        $company = Filament::getTenant() ?? Company::current();

        if (! ($company?->isPersonal() ?? false)) {
            return false;
        }

        return auth()->user()?->can('PersonalFinanceView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'fiscal_year_id' => TaxSchedule::defaultYearId(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Tax year')
                    ->options(FiscalYear::orderByDesc('start_date')->pluck('name', 'id'))
                    ->live()
                    ->helperText('July to June. FBR names this period for the year it ends in, so this app\'s 2025-2026 is FBR\'s Tax Year 2026.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('return-pack', 'Return Pack: Help'),
            Action::make('download')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->getPack()['pack'] !== null)
                ->action(function () {
                    $pack = $this->getPack()['pack'];

                    $pdf = Pdf::view('pdfs.personal-return-pack', ['pack' => $pack])
                        ->format('a4')
                        ->name('return-pack-'.$pack['year']->name.'.pdf');

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
     * @return array{pack: ?array<string, mixed>, error: ?string, filer_status: ?string}
     */
    public function getPack(): array
    {
        $fiscalYearId = $this->data['fiscal_year_id'] ?? null;

        if (! $fiscalYearId) {
            return ['pack' => null, 'error' => null, 'filer_status' => null];
        }

        $profile = PersonalTaxProfile::where('fiscal_year_id', $fiscalYearId)->first();

        try {
            $pack = app(PersonalReturnPack::class)->build((int) $fiscalYearId);
        } catch (RuntimeException $e) {
            // "No schedule seeded" must not look like "you owe nothing" — the
            // same rule TaxEstimate states, kept here for the same reason.
            return ['pack' => null, 'error' => $e->getMessage(), 'filer_status' => $profile?->filer_status];
        }

        return ['pack' => $pack, 'error' => null, 'filer_status' => $profile?->filer_status];
    }
}
