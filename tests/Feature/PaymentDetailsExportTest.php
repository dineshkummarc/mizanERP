<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\PaymentDetailsExport;
use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PaymentDetailsExportTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_it_writes_the_fbr_payment_details_layout_with_a_total_row(): void
    {
        $type = TransactionType::create(['name' => 'Rent u/s 155', 'code' => 'rent']);

        Payment::withoutEvents(function () use ($type): void {
            Payment::create([
                'payable_type' => Employee::class, 'payable_id' => 999,
                'transaction_type_id' => $type->id, 'amount' => 80000, 'details' => 'Office Rent',
                'reference' => 'IT2024-001', 'value_date' => '2024-11-07', 'status' => 'approved',
            ]);
            Payment::create([
                'payable_type' => Employee::class, 'payable_id' => 998,
                'transaction_type_id' => $type->id, 'amount' => 136497, 'details' => 'Salary',
                'reference' => 'IT2024-002', 'value_date' => '2024-11-08', 'status' => 'paid',
            ]);
        });

        $path = tempnam(sys_get_temp_dir(), 'test-export-').'.xlsx';
        app(PaymentDetailsExport::class)->writeToFile(Payment::query(), $path);

        $rows = $this->readRows($path);
        @unlink($path);

        // Header row matches the FBR column order.
        $this->assertSame('Payment Id', $rows[0][0]);
        $this->assertSame('Paid Amount', $rows[0][8]);
        $this->assertSame('Claimed', $rows[0][12]);

        // Two data rows + a total row.
        $this->assertCount(4, $rows);
        $this->assertSame('IT2024-001', $rows[1][1]);   // CPR No mapped from reference
        $this->assertSame('Rent u/s 155', $rows[1][4]); // Section

        // Summary row.
        $last = end($rows);
        $this->assertSame('Total Paid Amount', $last[7]);
        $this->assertSame('216497.00', $last[8]);
    }

    /** @return array<int, array<int, string>> */
    protected function readRows(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => (string) $c, $row->toArray());
            }
            break;
        }
        $reader->close();

        return $rows;
    }
}
