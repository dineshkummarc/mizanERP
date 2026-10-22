<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The document behind the entry — `docs/bexio-gap-plan.md` Phase 2, item 9.
     *
     * A purchase bill and a manual journal could carry no attachment at all, while an expense claim and a
     * petty-cash voucher could. One path each, on the same public tenant disk the petty-cash receipts use.
     * Changeable after posting: the supplier's PDF often arrives after the bill was keyed from it, and a
     * swap is in the audit log either way.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('memo');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('memo');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('attachment_path'));
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropColumn('attachment_path'));
    }
};
