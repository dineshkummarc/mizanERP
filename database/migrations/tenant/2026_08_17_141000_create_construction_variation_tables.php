<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variations and change orders — `docs/construction-management-plan.md` §9.
 *
 * One table for both, because they are the same thing under two names (§8.3).
 *
 * **The state that construction actually lives in is `approved_in_principle` with `is_price_provisional`**:
 * instructed, work proceeding, price disputed for four months. Modelling it as either approved or not "forces a
 * choice between certifying money nobody agreed and forecasting a cost the job is already incurring" — so it is
 * a state and a flag, and the rule that follows is the sharpest one in §9:
 *
 * **A provisionally priced variation is excluded from the certified contract sum and included in the
 * forecast.** Two named scopes carry it — `agreed()` and `forecast()` — and there is **no bare
 * `where('status', 'approved')` anywhere in the module**, which `ConstructionVariationTest` asserts against the
 * source. One boolean, two audiences, and conflating them is how a job reports a margin it does not have for
 * two quarters running.
 *
 * **Three money columns rather than one**, and NEC4 is the reason to keep them: quoted is what the contractor
 * asked, assessed is what the certifier decided, approved is what the parties agreed. A Project Manager's own
 * assessment under NEC4 is simply an assessed amount differing from the quoted one, which is why that regime
 * needs no schema change here (§11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('construction_contracts')->cascadeOnDelete();
            $table->string('variation_number')->comment('VO-12 under FIDIC, CO-12 under AIA');

            $table->enum('origin', [
                'employer_instruction', 'engineer_instruction', 'architect_supplemental', 'contractor_proposal',
                'rfi', 'ncr', 'site_condition', 'design_change', 'provisional_sum_expenditure', 'dayworks',
                'claim', 'compensation_event',
            ])->default('engineer_instruction');

            /*
             * What caused it — the RFI, the NCR, the instruction. A **plain-column morph**, so the type is
             * written through `ModuleMap::alias()` in a mutator: `enforceMorphMap()` does not cover plain
             * columns, and §18.2 names this table among the five exposed. Without it the fully-qualified class
             * name goes into the column and the day that class moves the query stops matching with no error.
             */
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            // Why it is a variation at all rather than included work. Read at adjudication.
            $table->text('justification')->nullable();

            // FIDIC 12.3 and 13.3's valuation hierarchy: contract rates, then pro-rata, then a new agreed rate.
            $table->enum('valuation_method', [
                'rates_in_contract', 'pro_rata_rates', 'new_rate_agreed', 'dayworks', 'lump_sum',
                'cost_plus_percentage',
            ])->default('rates_in_contract');

            $table->enum('status', [
                'draft', 'submitted', 'priced', 'approved_in_principle', 'approved', 'rejected', 'incorporated',
            ])->default('draft');

            $table->decimal('quoted_amount', 15, 2)->nullable()->comment('What the contractor asked');
            $table->decimal('assessed_amount', 15, 2)->nullable()->comment('What the certifier decided');
            $table->decimal('approved_amount', 15, 2)->nullable()->comment('What the parties agreed');

            /*
             * The flag the whole section turns on. Instructed and proceeding, price not agreed: forecast it,
             * do not certify it.
             */
            $table->boolean('is_price_provisional')->default(false);
            $table->enum('provisional_confidence', ['low', 'medium', 'high'])->nullable()
                ->comment('How firm the provisional figure is, for the forecast reader');

            // Time and money are separate awards and routinely disagree — an extension with no money is
            // ordinary, and so is money with no extension.
            $table->smallInteger('time_impact_days')->nullable()->comment('Claimed');
            $table->smallInteger('time_granted_days')->nullable()->comment('Awarded');
            $table->enum('eot_status', ['none', 'claimed', 'granted', 'rejected'])->default('none');

            // The stamps, each its own date because each is a different act by a different person, and the
            // gaps between them are what a delay claim is made of.
            $table->date('instructed_on')->nullable();
            $table->date('submitted_on')->nullable();
            $table->date('priced_on')->nullable();
            $table->date('approved_on')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('incorporated_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            // Numbering is per contract and must have no gaps (§8.3).
            $table->unique(['contract_id', 'variation_number']);
            $table->index(['contract_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        /*
         * §9. `previous_quantity` and `previous_rate` are the audit trail for the one permitted in-place edit:
         * a remeasure or a rate change writes the new figures onto the contract item and keeps the old ones
         * here, so "what was it before VO-12" is answerable without reading a diff of the schedule.
         */
        Schema::create('construction_variation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained('construction_variations')->cascadeOnDelete();

            /*
             * **`add` writes a new contract item; `omit` writes a negative one.** Reducing the original
             * destroys the audit trail *and* breaks certificates already issued, because the certificate's
             * "completed to date" column would exceed the "scheduled value" it is measured against — which is
             * impossible on the face of the form. "A negative line is ugly on the page and correct in the
             * ledger"; the print layer may net the pair.
             */
            $table->enum('action', ['add', 'omit', 'remeasure', 'rate_change'])->default('add');

            // What it acts on. Null for an `add`, which has no existing line.
            $table->foreignId('contract_item_id')->nullable()
                ->constrained('construction_contract_items')->nullOnDelete();

            $table->string('item_no')->nullable()->comment('For an add: the line number it will print as');
            $table->text('description')->nullable();
            $table->foreignId('cost_code_id')->nullable()->constrained('construction_cost_codes')->nullOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('construction_wbs_nodes')->nullOnDelete();

            $table->string('unit', 16)->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('rate', 14, 4)->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            // The audit trail for a remeasure or a rate change.
            $table->decimal('previous_quantity', 14, 4)->nullable();
            $table->decimal('previous_rate', 14, 4)->nullable();

            // The line this item wrote when the variation was incorporated, so incorporation is idempotent and
            // traceable in both directions.
            $table->foreignId('resulting_item_id')->nullable()
                ->constrained('construction_contract_items')->nullOnDelete();

            $table->timestamps();

            $table->index(['variation_id', 'action']);
            $table->index('contract_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_variation_items');
        Schema::dropIfExists('construction_variations');
    }
};
