<?php

namespace App\Modules\Payroll\Filament\Support;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\CertificateBatch;
use App\Modules\Payroll\Services\WithholdingCertificate;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Tax deduction certificates for a year, for every selected employee at once — `docs/bexio-gap-plan.md`
 * Phase 5, item 18. Contributed to the Employees list from Payroll's provider, because the certificate
 * is Payroll's and Employees names nothing.
 */
class WithholdingCertificatesBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('withholdingCertificates')
            ->label('Tax certificates for a year')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (): bool => modules()->enabled('payroll') && (auth()->user()?->can('PayslipView') ?? false))
            ->modalHeading('Certificates of tax deducted')
            ->modalDescription('One certificate per selected employee, for the year chosen — the same rows and figures as the '
                .'withholding statement filed with FBR. Rendered fresh and never stored; anybody with nothing withheld, or no '
                .'CNIC on record, is left out and named.')
            ->modalSubmitActionLabel('Issue')
            ->schema([
                Select::make('fiscal_year_id')
                    ->label('Tax year')
                    ->options(fn (): array => FiscalYear::query()->orderByDesc('start_date')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => FiscalYear::query()->where('is_active', true)->value('id'))
                    ->required(),
                Radio::make('deliver')
                    ->label('Deliver as')
                    ->options(['zip' => 'One zip to download', 'email' => 'An email to each employee, certificate attached'])
                    ->default('zip')
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (array $data, Collection $records) {
                $year = FiscalYear::query()->findOrFail($data['fiscal_year_id']);
                $certificates = app(WithholdingCertificate::class);

                $batch = (new CertificateBatch)->run(
                    $records,
                    fn (Employee $employee): array => $certificates->missingFor($employee, $year),
                    fn (Employee $employee) => $certificates->renderPdf($employee, $year),
                    $data['deliver'],
                    "tax deduction certificate for {$year->name}",
                );

                Notification::make()
                    ->title('Tax certificates: '.$batch->summary($data['deliver']))
                    ->{$batch->skipped === [] ? 'success' : 'warning'}()
                    ->persistent()
                    ->send();

                if ($data['deliver'] === 'zip' && ! $batch->bundle->isEmpty()) {
                    return $batch->bundle->download("tax-certificates-{$year->name}.zip");
                }

                return null;
            });
    }
}
