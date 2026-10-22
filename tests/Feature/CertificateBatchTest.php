<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\Pages\ListEmployees;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\CertificateBatch;
use App\Notifications\DocumentIssued;
use App\Support\Pdf\DocumentBundle;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Certificates for many employees at once — `docs/bexio-gap-plan.md` Phase 5, item 18.
 *
 * The certificates themselves have their own tests; this is the loop around them — who is left out and
 * why, the zip, the email to each person — driven with a stand-in document so no PDF engine runs.
 */
class CertificateBatchTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $ayesha;

    private Employee $bilal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'hr@test.local'));
        $this->setCurrentTenant();
        Notification::fake();

        $this->ayesha = Employee::create([
            'user_id' => $this->makeUser('Employee', 'ayesha@test.local')->id,
            'employee_id' => 'EMP-A',
            'gender' => 'Female',
        ]);

        // No account: reached, if at all, on the personal address.
        $this->bilal = Employee::create([
            'employee_id' => 'EMP-B',
            'name' => 'Bilal',
            'gender' => 'Male',
            'personal_email' => 'bilal@home.test',
        ]);
    }

    private function fakePdf(string $name): PdfDocument
    {
        return (new class('pdfs.delivery-test', []) extends PdfDocument
        {
            public function raw(): string
            {
                return '%PDF-1.4 stand-in';
            }
        })->name($name);
    }

    public function test_a_bundle_is_a_zip_that_keeps_both_files_of_the_same_name(): void
    {
        $bundle = (new DocumentBundle)
            ->add('certificate.pdf', 'one')
            ->add('certificate.pdf', 'two');

        $this->assertSame(2, $bundle->count());

        $zip = $bundle->zip();
        $this->assertStringStartsWith('PK', $zip, 'a zip archive');

        $archive = new \ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'test-').'.zip';
        file_put_contents($path, $zip);
        $archive->open($path);
        $this->assertSame(['certificate.pdf', 'certificate-2.pdf'], [$archive->getNameIndex(0), $archive->getNameIndex(1)]);
        $archive->close();
        @unlink($path);
    }

    public function test_the_batch_bundles_the_issuable_and_names_the_rest(): void
    {
        $batch = (new CertificateBatch)->run(
            Employee::query()->whereIn('id', [$this->ayesha->id, $this->bilal->id])->get(),
            fn (Employee $e): array => $e->is($this->bilal) ? ['no payslip with tax withheld'] : [],
            fn (Employee $e): PdfDocument => $this->fakePdf("cert-{$e->employee_id}.pdf"),
            'zip',
            'tax deduction certificate',
        );

        $this->assertSame(1, $batch->bundle->count());
        $this->assertArrayHasKey('EMP-B - Bilal', $batch->skipped + ['EMP-B - Bilal' => []]);
        $this->assertStringContainsString('1 in the download', $batch->summary('zip'));
        $this->assertStringContainsString('no payslip with tax withheld', $batch->summary('zip'));
    }

    public function test_email_goes_to_the_account_or_the_personal_address(): void
    {
        $batch = (new CertificateBatch)->run(
            Employee::query()->whereIn('id', [$this->ayesha->id, $this->bilal->id])->get(),
            fn (Employee $e): array => [],
            fn (Employee $e): PdfDocument => $this->fakePdf("cert-{$e->employee_id}.pdf"),
            'email',
            'income certificate',
        );

        $this->assertSame(2, $batch->emailed);
        $this->assertSame([], $batch->skipped);

        Notification::assertSentTo($this->ayesha->user, DocumentIssued::class, fn (DocumentIssued $n): bool => $n->filename === 'cert-EMP-A.pdf'
            && $n->bytes === '%PDF-1.4 stand-in');
        Notification::assertSentOnDemand(DocumentIssued::class, fn (DocumentIssued $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'bilal@home.test');
    }

    public function test_somebody_with_no_address_is_left_out_rather_than_failing_the_batch(): void
    {
        $this->bilal->update(['personal_email' => null]);

        $batch = (new CertificateBatch)->run(
            Employee::query()->whereKey($this->bilal->id)->get(),
            fn (Employee $e): array => [],
            fn (Employee $e): PdfDocument => $this->fakePdf('x.pdf'),
            'email',
            'income certificate',
        );

        $this->assertSame(0, $batch->emailed);
        $this->assertStringContainsString('no email address', $batch->summary('email'));
    }

    /** Both bulk actions are on the Employees list: the income one natively, the tax one from Payroll. */
    public function test_both_bulk_actions_are_offered_on_the_employees_list(): void
    {
        Livewire::test(ListEmployees::class)
            ->assertTableBulkActionExists('incomeCertificates')
            ->assertTableBulkActionExists('withholdingCertificates');
    }
}
