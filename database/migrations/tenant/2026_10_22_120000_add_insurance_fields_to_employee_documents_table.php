<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An insurance policy as a document that expires — `docs/bexio-gap-plan.md` Phase 5, item 19.
     *
     * The plan sketched an `employee_insurances` table. A policy is a document with a number, a start, an
     * end and a scan — exactly what `employee_documents` already holds, with the reminders, the report and
     * the dashboard tile already watching its expiry. Three columns a policy has that a visa does not,
     * null on everything else, and `kind = insurance` to show them.
     */
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->string('insurer')->nullable()->after('number');
            $table->decimal('cover_amount', 15, 2)->nullable()->after('insurer')->comment('Sum insured');
            $table->decimal('premium', 15, 2)->nullable()->after('cover_amount')->comment('Annual premium, in the base currency');
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', fn (Blueprint $table) => $table->dropColumn(['insurer', 'cover_amount', 'premium']));
    }
};
