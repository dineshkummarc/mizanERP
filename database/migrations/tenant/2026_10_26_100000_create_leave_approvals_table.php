<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-step leave approval — docs/hrms-plan.md §11, "a real request and not designed here".
 *
 * A table, not a column, which is what §11 asked for: `decided_by` stays the one decision that approved
 * or refused the request, and the steps before it live here. A company that sets no chain
 * (`leave.approval_chain`, empty by default) never writes a row and approves in one step, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step')->comment('1 is the first approval; the chain roles follow');
            $table->string('role')->nullable()->comment('The chain role this step required; null for the first, which anybody who may decide takes');
            // A landlord user, so no foreign key — the same as leave_requests.decided_by.
            $table->foreignId('approved_by')->index();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['leave_request_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_approvals');
    }
};
