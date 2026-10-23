<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salary_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->decimal('min_amount', 15, 2)->comment('E.g., 600000');
            $table->decimal('max_amount', 15, 2)->nullable()->comment('Null means Above this amount');
            $table->decimal('fixed_tax', 15, 2)->default(0)->comment('E.g., 116000');
            $table->decimal('percentage', 5, 2)->default(0)->comment('E.g., 20 for 20%');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_slabs');
    }
};
