<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The accrual an approved claim books — `docs/bexio-gap-plan.md` Phase 3, item 13.
     *
     * `ExpenseReports` said it outright: an approved, unpaid claim is "an accrued liability in no
     * account". It is in one now, from approval — see `ExpenseClaimPosting` — and this is the entry's
     * id, nullable for the same reason every other document's is: a claim whose entry was reversed keeps
     * its history, and every claim approved before this column existed has none.
     */
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('payment_id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', fn (Blueprint $table) => $table->dropConstrainedForeignId('journal_entry_id'));
    }
};
