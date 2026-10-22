<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a component's amount is *per* — `docs/bexio-gap-plan.md` Phase 5, item 20.
     *
     * `monthly` is every existing component: the amount on the package is the amount on the payslip.
     * `per_hour` multiplies it by the hours attendance recorded in the month, which is what an hourly
     * wage is. `annual` pays the whole amount in the one month named — a 13th salary, an annual bonus —
     * and nothing in the other eleven. No new column on payslips: the month's figure lands in
     * `payslip_components` exactly as a monthly one does.
     */
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            $table->string('basis', 16)->default('monthly')->after('kind')->comment('monthly|per_hour|annual');
            $table->string('pays_in_month', 16)->nullable()->after('basis')->comment('annual only: the month name it is paid in');
        });
    }

    public function down(): void
    {
        Schema::table('pay_components', fn (Blueprint $table) => $table->dropColumn(['basis', 'pays_in_month']));
    }
};
