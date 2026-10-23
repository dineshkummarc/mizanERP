<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmployeesTable extends Migration
{
    public function up()
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index(); // soft ref -> landlord users
            $table->string('employee_id')->unique();
            $table->string('phone')->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->string('nic')->nullable();
            $table->foreignId('bank_id')->nullable(); // FK added in create_banks_table (banks migrates later)
            $table->string('bank_code')->nullable()->comment('Beneficiary bank code / SWIFT / IMD for bank payment files');
            $table->string('bank_short_code')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('iban_no')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->enum('gender', ['Male', 'Female']);
            $table->boolean('is_active')->default(true); // Status
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('employees');
    }
}
