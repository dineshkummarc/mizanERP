<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts and documents stored on the contact — docs/bexio-gap-plan.md §3.7.
 *
 * The same shape as employee_documents: a kind, a number, a term and a scan, with the expiry watched by
 * the same once-per-threshold rule (`expiry_notified_at_days`, whose own migration explains it). The
 * signed contract, the NDA, a supplier's trade licence or tax registration — the things that lived in
 * somebody's mailbox and were found by asking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();

            $table->string('kind')->comment('contract|nda|licence|registration|tax-certificate|bank-letter|other');
            $table->string('title');
            $table->string('number')->nullable()->comment('The contract or licence number');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->comment('Null for a document that never lapses');

            $table->string('file_path')->nullable();
            $table->text('notes')->nullable();
            // A landlord user, so no foreign key — the same as employee_documents.verified_by.
            $table->foreignId('uploaded_by')->nullable()->index();

            $table->unsignedSmallInteger('expiry_notified_at_days')->nullable()
                ->comment('The threshold last warned at. Notify on transitions, never on every run.');

            $table->timestamps();

            $table->index(['expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_documents');
    }
};
