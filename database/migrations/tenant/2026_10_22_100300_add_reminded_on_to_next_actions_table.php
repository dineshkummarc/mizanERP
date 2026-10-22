<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the assignee was last told this is due — `docs/bexio-gap-plan.md` Phase 2, item 6.
     *
     * One date, so the reminder is sent once per due date rather than every morning until the action is
     * done: `docs/crms-plan.md` §9 — "a daily digest is one message; per-action reminders are thirty". A
     * snooze moves the effective date, and a reminder older than that date counts as not sent.
     */
    public function up(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->date('reminded_on')->nullable()->after('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::table('next_actions', fn (Blueprint $table) => $table->dropColumn('reminded_on'));
    }
};
