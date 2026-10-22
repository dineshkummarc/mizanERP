<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a supplier is paid — `docs/bexio-gap-plan.md` Phase 3, item 11.
     *
     * `contacts.bank_id` existed "for paying suppliers through the bank payment flow" and nothing else did:
     * the flow reads an account number or an IBAN off the payee, and a contact carried neither, which is
     * why a bill could only reach the bank file by being retyped as a beneficiary. The same two columns a
     * beneficiary has, so `BankFileAccount::resolve()` reads a supplier exactly as it reads one.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('account_no', 50)->nullable()->after('bank_id');
            $table->string('iban', 34)->nullable()->after('account_no');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['account_no', 'iban']));
    }
};
