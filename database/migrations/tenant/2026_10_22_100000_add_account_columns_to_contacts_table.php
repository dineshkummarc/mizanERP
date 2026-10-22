<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The contact becomes an account — `docs/bexio-gap-plan.md` Phase 2, item 5.
     *
     * Three plain columns, each the smallest shape that answers its question:
     *
     *  - `category` is free text offered back as a datalist of what has been typed before, not a
     *    settings table. A company groups customers by industry, region or tier; whichever it is, the
     *    second contact in a category gets it from the first, and nobody administers a list.
     *  - `owner_employee_id` is a column with no relation on the model. Invoicing may not name
     *    Employees (ModuleBoundaryTest), so the picker and the label go through
     *    `App\Support\EmployeeOptions`, exactly as `construction_workers.employee_id` does. The
     *    foreign key is schema, not PHP, and costs the boundary nothing.
     *  - `default_discount_pct` is applied when a catalogue price lands on a line — the invoice
     *    carries the resulting price, as a converted quote already does, so nothing downstream of
     *    the line changes.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('kind')->index();
            $table->foreignId('owner_employee_id')->nullable()->after('category')
                ->constrained('employees')->nullOnDelete();
            $table->decimal('default_discount_pct', 5, 2)->default(0)->after('default_tax_rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_employee_id');
            $table->dropColumn(['category', 'default_discount_pct']);
        });
    }
};
