<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How this bank's statement CSV is laid out — `docs/bexio-gap-plan.md` Phase 3, item 12.
     *
     * Which column holds the date, the narration, the reference, and either one signed amount or a credit
     * and a debit column, plus the date format when it is not one Carbon guesses. Saved from the first
     * import against this account, so the second statement from the same bank needs no mapping at all.
     * JSON rather than six columns because the set will grow by bank, not by design.
     */
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->json('statement_mapping')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', fn (Blueprint $table) => $table->dropColumn('statement_mapping'));
    }
};
