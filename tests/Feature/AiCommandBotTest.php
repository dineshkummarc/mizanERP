<?php

namespace Tests\Feature;

use App\Filament\Livewire\CommandBar;
use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Models\TransactionTypeAlias;
use App\Modules\Accounting\Services\CommandBooker;
use App\Modules\Accounting\Services\CommandInterpreter;
use App\Modules\Accounting\Support\AmountWords;
use App\Modules\Accounting\Support\CommandGrammar;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\Ai\FakeStructuredModel;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;
use Database\Seeders\TransactionTypeAliasSeeder;
use Database\Seeders\TransactionTypeSeeder;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The AI command bot — `docs/ai-command-bot-plan.md`, Phase 1.
 *
 * **The file exists for §2**: a command resolves to a direction of money, and that direction decides which
 * leg of a two-line entry is the debit. Everything else here — amount parsing, category resolution, the
 * confirmation gate — protects that one figure from being right about the wrong thing.
 *
 * The model is faked throughout. Sentence-to-fields is the only non-deterministic step in the feature and
 * the only one that is stood in for; resolution, the sign rules, and the booking are asserted exactly.
 */
class AiCommandBotTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private FakeStructuredModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'commands@test.local'));

        // The categories a command resolves against — `rent` → 5700, `utilities` → 5750, and so on.
        $this->seed(TransactionTypeSeeder::class);

        // The feature flag, which nothing read until the bar turned out to be unreachable and the check for
        // "is this switched on at all" went in beside the check for "can it reach a parser".
        config(['ai.enabled' => true]);

        $this->model = new FakeStructuredModel;
        $this->app->instance(StructuredModel::class, $this->model);
    }

    /** @param array<string, mixed> $overrides */
    private function reply(array $overrides = []): array
    {
        return $overrides + [
            'direction' => 'out',
            'amount' => '25000',
            'transaction_type_code' => 'rent',
            'date' => null,
            'description' => null,
            'confidence' => 0.95,
            'ambiguity' => [],
        ];
    }

    private function interpret(string $utterance, array $reply = [])
    {
        $this->model->queue($this->reply($reply));

        return app(CommandInterpreter::class)->interpret($utterance);
    }

    // ------------------------------------------------------------------ §2: the sign

    /**
     * **The exit condition of Phase 1.** Money out credits the cash account and debits the counter.
     *
     * A register account is always an asset (`registerAccounts()` filters `type = asset`), so by the
     * standard rules a credit decreases it — which is what paying rent does to cash. The counter-account
     * is an expense, and debiting an expense increases it.
     */
    public function test_money_out_credits_cash_and_debits_the_expense(): void
    {
        $interpretation = $this->interpret('rent 25000 out');
        $entry = app(CommandBooker::class)->book($interpretation);

        $cash = $interpretation->registerAccount;
        $rent = TransactionType::byCode('rent')->account;

        $cashLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', $cash->id)->firstOrFail();
        $rentLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', $rent->id)->firstOrFail();

        $this->assertEquals(25000, $cashLine->credit_amount, 'money out must CREDIT the cash account — the asset decreases');
        $this->assertEquals(0, $cashLine->debit_amount);

        $this->assertEquals(25000, $rentLine->debit_amount, 'the expense is DEBITED — an expense increases on the debit side');
        $this->assertEquals(0, $rentLine->credit_amount);
    }

    /**
     * And the mirror: money in debits cash and credits the counter-account.
     *
     * Deliberately against a **liability** (2100 Income Tax Payable) rather than an expense, because that
     * is the harder half of §2.2's claim: the counter-leg is fixed by "debits equal credits" alone, and
     * the account's own type then decides what the leg *means*. Crediting a liability increases it, which
     * is what withholding tax against the cash you hold does — and the bot classified nothing to get here.
     */
    public function test_money_in_debits_cash_and_credits_a_liability(): void
    {
        $interpretation = $this->interpret('tax withheld 40000 in', [
            'direction' => 'in',
            'amount' => '40000',
            'transaction_type_code' => 'tax-payment',
        ]);

        $this->assertSame('in', $interpretation->direction, 'the fixture must resolve, or the assertions below prove nothing');
        $this->assertSame('liability', $interpretation->category->account->type);

        $entry = app(CommandBooker::class)->book($interpretation);

        $cash = $interpretation->registerAccount;
        $cashLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', $cash->id)->firstOrFail();
        $counterLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', '!=', $cash->id)->firstOrFail();

        $this->assertEquals(40000, $cashLine->debit_amount, 'money in must DEBIT the cash account — the asset increases');
        $this->assertEquals(0, $cashLine->credit_amount);
        $this->assertEquals(40000, $counterLine->credit_amount, 'crediting a liability increases it — the standard rule, applied without the bot knowing the type');
    }

    /**
     * The ordinary money-in case: a receipt against revenue.
     *
     * These categories did not exist until this feature needed them — the list was written for the payment
     * screens, where money only ever goes out, so nothing had asked for a receipt's category before.
     */
    public function test_money_in_credits_revenue(): void
    {
        $interpretation = $this->interpret('consulting fee 40000 received', [
            'direction' => 'in',
            'amount' => '40000',
            'transaction_type_code' => 'service-revenue',
        ]);

        $this->assertSame('income', $interpretation->category->account->type);

        $entry = app(CommandBooker::class)->book($interpretation);

        $cash = $interpretation->registerAccount;
        $cashLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', $cash->id)->firstOrFail();
        $revenueLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', '!=', $cash->id)->firstOrFail();

        $this->assertEquals(40000, $cashLine->debit_amount, 'the asset increases on the debit side');
        $this->assertEquals(40000, $revenueLine->credit_amount, 'revenue increases on the credit side');
    }

    /** Whatever the direction, the entry balances — the rule the whole of double-entry rests on. */
    public function test_the_entry_balances_either_way(): void
    {
        foreach ([['out', 'rent'], ['in', 'tax-payment']] as [$direction, $code]) {
            $entry = app(CommandBooker::class)->book(
                $this->interpret("{$code} 1000 {$direction}", ['direction' => $direction, 'amount' => '1000', 'transaction_type_code' => $code])
            );

            $lines = JournalEntryLine::where('journal_entry_id', $entry->id)->get();

            $this->assertEquals(
                $lines->sum('debit_amount'),
                $lines->sum('credit_amount'),
                "total debits must equal total credits ({$direction})",
            );
        }
    }

    /** The confirmation states the effect, never the word the user typed — §2.3. */
    public function test_the_confirmation_states_the_effect_not_the_users_word(): void
    {
        $out = $this->interpret('rent 25000 credit');
        $this->assertStringContainsString('Money out', $out->effectLine());
        $this->assertStringContainsString('leaves', $out->effectLine());
        $this->assertStringNotContainsStringIgnoringCase('credit', $out->effectLine());

        $in = $this->interpret('tax withheld 200000 debit', [
            'direction' => 'in', 'amount' => '200000', 'transaction_type_code' => 'tax-payment',
        ]);
        $this->assertStringContainsString('Money in', $in->effectLine());
        $this->assertStringContainsString('arrives', $in->effectLine());
        $this->assertStringNotContainsStringIgnoringCase('debit', $in->effectLine());
    }

    /**
     * `direction` never carries extra friction — §6.
     *
     * By §2.1 it follows from the rules rather than being inferred, so even a model that mistakenly
     * reports it as ambiguous must not make the user click it.
     */
    public function test_direction_is_never_treated_as_ambiguous(): void
    {
        $interpretation = $this->interpret('rent 25000 jama', ['ambiguity' => ['direction']]);

        $this->assertSame([], $interpretation->flags, 'direction must be filtered out of the ambiguity flags');
        $this->assertFalse($interpretation->needsExplicitConfirmation());
    }

    // ------------------------------------------------------------------ resolution

    public function test_a_complete_command_resolves_every_slot(): void
    {
        $interpretation = $this->interpret('rent 25000 out');

        $this->assertTrue($interpretation->isComplete());
        $this->assertSame([], $interpretation->questions);
        $this->assertSame('out', $interpretation->direction);
        $this->assertEquals(25000.0, $interpretation->amount);
        $this->assertSame('rent', $interpretation->category->code);
        $this->assertTrue($interpretation->date->isToday());
    }

    /** A category the model could not place is a question, never a fallback to "Other" — §3.2. */
    public function test_an_unplaced_category_asks_rather_than_guessing(): void
    {
        $interpretation = $this->interpret('25000 out', ['transaction_type_code' => null]);

        $this->assertFalse($interpretation->isComplete());
        $this->assertContains('What should this be filed under?', $interpretation->questions);
        $this->assertNull($interpretation->category);
    }

    /** A code that is not this tenant's resolves to nothing — the reply is not a trusted key. */
    public function test_a_category_code_the_tenant_does_not_have_is_refused(): void
    {
        $interpretation = $this->interpret('rent 25000 out', ['transaction_type_code' => 'not-a-real-code']);

        $this->assertNull($interpretation->category);
        $this->assertFalse($interpretation->isComplete());
    }

    public function test_a_missing_direction_asks(): void
    {
        $interpretation = $this->interpret('rent 25000', ['direction' => null]);

        $this->assertContains('Was this money in or money out?', $interpretation->questions);
        $this->assertFalse($interpretation->isComplete());
    }

    // ------------------------------------------------------------------ §6: the gate

    public function test_an_incomplete_command_cannot_be_booked(): void
    {
        $interpretation = $this->interpret('25000 out', ['transaction_type_code' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not resolved yet');

        app(CommandBooker::class)->book($interpretation);
    }

    public function test_a_large_amount_needs_the_figure_clicked(): void
    {
        config(['ai.commands.confirm_threshold' => 100000]);

        $this->assertFalse($this->interpret('rent 25000 out')->needsExplicitConfirmation());
        $this->assertTrue(
            $this->interpret('rent 500000 out', ['amount' => '500000'])->needsExplicitConfirmation(),
            'above the threshold the amount must be clicked, not Entered',
        );
    }

    public function test_a_flagged_slot_needs_the_figure_clicked(): void
    {
        $this->assertTrue(
            $this->interpret('rent maybe 25000 out', ['ambiguity' => ['amount']])->needsExplicitConfirmation()
        );
    }

    // ------------------------------------------------------------------ §9: the audit trail

    public function test_every_command_is_recorded_whether_or_not_it_books(): void
    {
        $booked = $this->interpret('rent 25000 out');
        app(CommandBooker::class)->book($booked);

        $this->assertSame(CommandUtterance::OUTCOME_BOOKED, $booked->utterance->refresh()->outcome);
        $this->assertNotNull($booked->utterance->journal_entry_id);
        $this->assertSame('rent 25000 out', $booked->utterance->utterance);

        $abandoned = $this->interpret('something odd', ['transaction_type_code' => null]);
        $this->assertSame(CommandUtterance::OUTCOME_NEEDS_INPUT, $abandoned->utterance->refresh()->outcome);
        $this->assertNull($abandoned->utterance->journal_entry_id);

        app(CommandBooker::class)->cancel($abandoned, 'user dismissed');
        $this->assertSame(CommandUtterance::OUTCOME_CANCELLED, $abandoned->utterance->refresh()->outcome);
    }

    /** The model's own reply is kept verbatim — §9 needs what the machine made of the sentence. */
    public function test_the_raw_model_reply_is_kept(): void
    {
        $interpretation = $this->interpret('rent 25000 out');

        $this->assertSame('rent', $interpretation->utterance->parsed['transaction_type_code']);
        $this->assertSame(0.95, $interpretation->utterance->parsed['confidence']);
    }

    /** Commands that resolved to nothing are findable — the raw material for the alias table. */
    public function test_unresolved_commands_are_queryable(): void
    {
        $this->interpret('rent 25000 out');
        $this->interpret('gizmo 500 out', ['transaction_type_code' => null]);

        $this->assertSame(1, CommandUtterance::unresolved()->count());
    }

    /** A model that cannot be reached is a failure, not a misunderstanding — it says so and books nothing. */
    public function test_an_unreachable_model_fails_loudly_and_books_nothing(): void
    {
        $this->model->queue(new ModelUnavailable('rate limited'));

        $interpretation = app(CommandInterpreter::class)->interpret('rent 25000 out');

        $this->assertFalse($interpretation->isComplete());
        $this->assertSame(CommandUtterance::OUTCOME_FAILED, $interpretation->utterance->refresh()->outcome);
        $this->assertSame('rate limited', $interpretation->utterance->outcome_reason);
    }

    // ------------------------------------------------------------------ §3.1: amounts

    #[DataProvider('amounts')]
    public function test_amount_parsing(string $written, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, AmountWords::parse($written), 0.001, $written);
    }

    public static function amounts(): array
    {
        return [
            'plain' => ['25000', 25000.0],
            'grouped' => ['25,000', 25000.0],
            'decimal' => ['1250.50', 1250.50],
            'k suffix' => ['25k', 25000.0],
            'k spaced' => ['25 k', 25000.0],
            'lakh' => ['1.5 lakh', 150000.0],
            'lac' => ['2 lac', 200000.0],
            'crore' => ['1 crore', 10000000.0],
            'hazaar' => ['30 hazaar', 30000.0],
            'rupee noise' => ['Rs. 25,000/-', 25000.0],
            'urdu digits' => ['۲۵۰۰۰', 25000.0],
            'urdu digits with scale' => ['۲۵ hazaar', 25000.0],

            // Phase 2: the scale word in Urdu script as well as the digits.
            'urdu hazaar' => ['۲۵ ہزار', 25000.0],
            'urdu lakh' => ['۱.۵ لاکھ', 150000.0],
            'urdu crore' => ['۲ کروڑ', 20000000.0],
            'latin digits urdu scale' => ['25 ہزار', 25000.0],
            'arabic-indic digits' => ['٢٥٠٠٠', 25000.0],
        ];
    }

    /**
     * **The silent-zero guard.** `(float) '۲۵۰۰۰'` is `0.0` in PHP with no notice.
     *
     * Normalised above; here the case that must never pass silently is a numeral system this does not
     * know — it is refused by name rather than becoming a zero-amount entry.
     */
    public function test_an_unrecognised_numeral_system_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('digits this does not recognise');

        AmountWords::parse('൨൫൦൦൦'); // Malayalam digits
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AmountWords::parse('0');
    }

    public function test_an_unparseable_amount_becomes_a_question_not_an_exception(): void
    {
        $interpretation = $this->interpret('rent lots out', ['amount' => 'lots']);

        $this->assertFalse($interpretation->isComplete());
        $this->assertNotEmpty($interpretation->questions);
        $this->assertNull($interpretation->amount);
    }

    // ------------------------------------------------------------------ §8: the command bar

    /** Interpreting shows a proposal and books nothing — the second beat is the whole feature. */
    public function test_the_bar_proposes_without_booking(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->set('utterance', 'rent 25000 out')
            ->call('interpret')
            ->assertSet('preview.complete', true)
            ->assertSee('Money out');

        $this->assertSame(0, JournalEntryLine::count(), 'interpreting must not post anything');
    }

    public function test_confirming_books_it(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->set('utterance', 'rent 25000 out')
            ->call('interpret')
            ->call('confirm')
            ->assertSet('preview', null);

        $this->assertSame(2, JournalEntryLine::count(), 'a booked command is one balanced two-line entry');
        $this->assertSame(1, CommandUtterance::where('outcome', CommandUtterance::OUTCOME_BOOKED)->count());
    }

    /** Confirming twice must not book twice — the row's outcome is the lock. */
    public function test_a_command_cannot_be_confirmed_twice(): void
    {
        $this->model->queue($this->reply());

        $component = Livewire::test(CommandBar::class)
            ->set('utterance', 'rent 25000 out')
            ->call('interpret');

        $id = $component->get('utteranceId');

        $component->call('confirm');

        // Put the id back, as a replayed request or a double click would.
        $component->set('utteranceId', $id)->call('confirm')->assertSet('preview.error', 'That command is no longer waiting to be confirmed.');

        $this->assertSame(2, JournalEntryLine::count(), 'still one entry, not two');
    }

    /**
     * The id travels in component state, and component state is something the browser can change.
     *
     * Without the user scope on the lookup, a swapped id would confirm somebody else's pending command.
     */
    public function test_another_users_pending_command_cannot_be_confirmed(): void
    {
        $this->model->queue($this->reply());

        $theirs = app(CommandInterpreter::class)->interpret('rent 25000 out', userId: 999999);

        Livewire::test(CommandBar::class)
            ->set('utteranceId', $theirs->utterance->id)
            ->call('confirm')
            ->assertSet('preview.error', 'That command is no longer waiting to be confirmed.');

        $this->assertSame(0, JournalEntryLine::count());
        $this->assertSame(CommandUtterance::OUTCOME_NEEDS_INPUT, $theirs->utterance->refresh()->outcome);
    }

    /** An incomplete command offers no Confirm button at all. */
    public function test_an_incomplete_command_offers_no_confirm(): void
    {
        $this->model->queue($this->reply(['transaction_type_code' => null]));

        Livewire::test(CommandBar::class)
            ->set('utterance', '25000 out')
            ->call('interpret')
            ->assertSet('preview.complete', false)
            ->assertSee('What should this be filed under?')
            ->assertDontSee('Confirm');
    }

    /** Dismissing records the abandonment rather than dropping it — §9. */
    public function test_cancelling_is_recorded(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->set('utterance', 'rent 25000 out')
            ->call('interpret')
            ->call('cancel');

        $this->assertSame(1, CommandUtterance::where('outcome', CommandUtterance::OUTCOME_CANCELLED)->count());
    }

    // ------------------------------------------------------------------ the prompt

    /** Today's date rides in the user turn, never the cacheable system prompt — §4. */
    public function test_the_date_is_not_in_the_cacheable_prefix(): void
    {
        $this->interpret('rent 25000 out');

        $call = $this->model->calls[0];

        $this->assertStringNotContainsString(now()->toDateString(), $call['system'], 'a date in the system prompt invalidates every tenant cache at midnight');
        $this->assertStringContainsString(now()->toDateString(), $call['utterance']);
    }

    /** The category list is in the prompt, so the model picks rather than invents. */
    public function test_the_prompt_carries_this_tenants_categories(): void
    {
        $this->interpret('rent 25000 out');

        $this->assertStringContainsString('rent:', $this->model->calls[0]['system']);
    }

    // ------------------------------------------------------------------ Phase 4: the resolver seam

    private function enableExpenses(bool $on = true): void
    {
        foreach (['employees', 'payroll', 'accounting', 'expenses'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => $on || $module !== 'expenses', 'enabled' => $on || $module !== 'expenses'],
            );
        }
        modules()->flush();
    }

    private function employee(string $name): Employee
    {
        return Employee::create([
            'employee_id' => $name,
            'name' => $name,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    /**
     * **The seam's exit condition**: a second module resolves a command the cash resolver cannot.
     *
     * Different slots (an employee, no direction), a different module, and a different ending — a claim is
     * created *pending*, because a claim is a request for somebody else's money and the module already has
     * an approver for deciding it.
     */
    public function test_a_second_module_resolves_its_own_kind_of_command(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses();
        $ali = $this->employee('Ali Raza');

        $this->model->queue([
            'employee_id' => $ali->id,
            'amount' => '4500',
            'transaction_type_code' => 'fuel',
            'date' => null,
            'description' => 'Taxi to the airport',
            'confidence' => 0.9,
            'ambiguity' => [],
        ]);

        $interpretation = app(CommandInterpreter::class)->interpret('Ali claim 4500 fuel');

        $this->assertSame('expense-claim', $interpretation->resolverKey, 'the claim wording must route away from cash');
        $this->assertTrue($interpretation->isComplete());
        $this->assertStringContainsString('Expense claim', $interpretation->effectLine());
        $this->assertStringContainsString('pending approval', $interpretation->effectLine(), 'confirming a claim is not paying it');

        $claim = app(CommandBooker::class)->book($interpretation);

        $this->assertInstanceOf(ExpenseClaim::class, $claim);
        $this->assertSame(ExpenseClaim::STATUS_PENDING, $claim->status, 'a bot must not self-approve somebody else\'s money');
        $this->assertEquals(4500, $claim->amount);
        $this->assertSame($ali->id, $claim->employee_id);

        // No journal entry: this resolver posts nothing.
        $this->assertSame(0, JournalEntryLine::count());
        $this->assertNull($interpretation->utterance->refresh()->journal_entry_id);
        $this->assertSame('expense-claim', $interpretation->utterance->resolver);
    }

    /** An unclaimed utterance still goes to cash — the default resolver is where everything lands. */
    public function test_an_unclaimed_utterance_routes_to_cash(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses();

        $interpretation = $this->interpret('rent 25000 out');

        $this->assertSame('cash', $interpretation->resolverKey);
    }

    /**
     * **A module this tenant has not licensed contributes nothing to the prompt** — §7.
     *
     * Not "is refused after the fact": the reply to a refused command still describes the slots it was
     * offered, so a resolver that appeared and then declined would tell anybody who read it which modules
     * exist. Gating happens before the model call or it does not count.
     */
    public function test_an_unlicensed_module_never_reaches_the_prompt(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses(false);

        $this->interpret('Ali claim 4500 fuel');

        $system = $this->model->calls[0]['system'];

        $this->assertStringNotContainsString('expense-claim', $system);
        $this->assertStringNotContainsString('Who is claiming', $system);
        $this->assertStringNotContainsString('employee', mb_strtolower($system));

        // The licensed resolvers are offered and the unlicensed one is not. Named by absence rather than
        // as an exact list, because the list grows with the modules (quotes and invoices joined it in
        // docs/bexio-gap-plan.md Phase 4) and that is not what this test is about.
        $offered = array_map(fn ($r) => $r->key(), app(CommandInterpreter::class)->available());

        $this->assertNotContains('expense-claim', $offered, 'an unlicensed module is not offered');
        $this->assertContains('cash', $offered);
    }

    /** With the module off, a claim-worded command falls back to cash rather than failing. */
    public function test_a_claim_command_falls_back_to_cash_when_expenses_is_off(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses(false);

        $interpretation = $this->interpret('Ali claim 4500 fuel');

        $this->assertSame('cash', $interpretation->resolverKey);
    }

    /**
     * A proposal whose resolver has gone away cannot be committed.
     *
     * Re-checked at commit rather than only at interpret: a licence can lapse or a permission be revoked
     * in the seconds between the proposal and the confirmation, and those are exactly the seconds in
     * which a stale proposal must stop working rather than quietly still work.
     */
    public function test_a_proposal_whose_module_was_switched_off_cannot_be_committed(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses();
        $ali = $this->employee('Ali Raza');

        $this->model->queue([
            'employee_id' => $ali->id, 'amount' => '4500', 'transaction_type_code' => 'fuel',
            'date' => null, 'description' => null, 'confidence' => 0.9, 'ambiguity' => [],
        ]);

        $interpretation = app(CommandInterpreter::class)->interpret('Ali claim 4500 fuel');
        $this->assertTrue($interpretation->isComplete());

        // The licence lapses between proposing and confirming.
        $this->enableExpenses(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer available');

        app(CommandBooker::class)->book($interpretation);
    }

    /** Each resolver rebuilds its own proposal — the bar never learns either shape. */
    public function test_the_confirmation_round_trip_goes_through_the_resolver(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses();
        $ali = $this->employee('Ali Raza');

        $this->model->queue([
            'employee_id' => $ali->id, 'amount' => '4500', 'transaction_type_code' => 'fuel',
            'date' => null, 'description' => null, 'confidence' => 0.9, 'ambiguity' => [],
        ]);

        Livewire::test(CommandBar::class)
            ->set('utterance', 'Ali claim 4500 fuel')
            ->call('interpret')
            ->assertSet('preview.complete', true)
            ->assertSee('Expense claim')
            ->call('confirm');

        $this->assertSame(1, ExpenseClaim::count());
        $this->assertSame(0, JournalEntryLine::count());
    }

    /** Exactly one resolver may be the fallback, or routing is undefined. */
    public function test_exactly_one_resolver_is_the_default(): void
    {
        $this->setCurrentTenant();
        $this->enableExpenses();

        $defaults = array_filter(
            app(CommandInterpreter::class)->available(),
            fn ($r) => $r->isDefault(),
        );

        $this->assertCount(1, $defaults);
    }

    // ------------------------------------------------------------------ Phase 3: voice

    /**
     * **The rule voice exists under**: a transcript is shown, never acted on — §5.
     *
     * The recogniser itself lives in the browser and cannot be driven from here. What can be asserted is
     * everything on this side of it, and this is the assertion that matters: dictating fills the box and
     * stops. Nothing is interpreted, so nothing can be booked, until a person has read it.
     */
    public function test_dictation_fills_the_box_and_stops_there(): void
    {
        Livewire::test(CommandBar::class)
            ->call('dictated', 'kiraya pachees hazaar diya', 'en-PK')
            ->assertSet('utterance', 'kiraya pachees hazaar diya')
            ->assertSet('transcript', 'kiraya pachees hazaar diya')
            ->assertSet('speechLocale', 'en-PK')
            ->assertSet('preview', null);

        $this->assertSame(0, CommandUtterance::count(), 'dictating must not even open an utterance row');
        $this->assertSame([], $this->model->calls, 'and must not reach the model');
    }

    /** A second dictation clears any proposal on screen — the old preview belongs to different words. */
    public function test_dictating_again_clears_the_previous_proposal(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->set('utterance', 'rent 25000 out')
            ->call('interpret')
            ->assertSet('preview.complete', true)
            ->call('dictated', 'bijli 4000 diya', 'en-PK')
            ->assertSet('preview', null)
            ->assertSet('utteranceId', null);
    }

    /** What was heard is recorded beside what was submitted, so the two are separable afterwards. */
    public function test_the_transcript_is_recorded_beside_the_utterance(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->call('dictated', 'rent 25000 out', 'en-PK')
            ->call('interpret');

        $row = CommandUtterance::latest('id')->first();

        $this->assertSame('rent 25000 out', $row->transcript);
        $this->assertSame('en-PK', $row->locale);
        $this->assertFalse($row->wasCorrected());
    }

    /**
     * **Speech errors and parse errors are separately visible** — the exit condition of Phase 3.
     *
     * A transcript the person edited before submitting is the only direct evidence the recogniser got it
     * wrong. Overwriting the transcript with the correction — the obvious simplification — would destroy
     * exactly that evidence and make the two failure modes one.
     */
    public function test_a_corrected_transcript_is_visible_as_a_speech_error(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->call('dictated', 'rent 25000 out', 'en-PK')
            // The recogniser heard "25000"; the person meant 35000 and fixed it before submitting.
            ->set('utterance', 'rent 35000 out')
            ->call('interpret');

        $row = CommandUtterance::latest('id')->first();

        $this->assertTrue($row->wasCorrected());
        $this->assertSame('rent 25000 out', $row->transcript, 'the transcript must survive the correction');
        $this->assertSame('rent 35000 out', $row->utterance);

        $this->assertSame(1, CommandUtterance::mistranscribed()->count());
        $this->assertSame(1, CommandUtterance::dictated()->count());
    }

    /** A typed command has no transcript at all — that null is how typing is told from dictating. */
    public function test_a_typed_command_records_no_transcript(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->set('utterance', 'rent 25000 out')
            ->call('interpret');

        $row = CommandUtterance::latest('id')->first();

        $this->assertNull($row->transcript);
        $this->assertFalse($row->wasCorrected());
        $this->assertSame(0, CommandUtterance::dictated()->count());
        $this->assertSame(0, CommandUtterance::mistranscribed()->count());
    }

    /** Booking clears the dictation state, so the next command does not inherit the last one's transcript. */
    public function test_booking_clears_the_transcript(): void
    {
        $this->model->queue($this->reply());

        Livewire::test(CommandBar::class)
            ->call('dictated', 'rent 25000 out', 'en-PK')
            ->call('interpret')
            ->call('confirm')
            ->assertSet('transcript', null)
            ->assertSet('speechLocale', null)
            ->assertSet('utterance', '');
    }

    /** Voice is configurable off, and the mic disappears with it rather than merely doing nothing. */
    public function test_voice_can_be_switched_off(): void
    {
        // Asserted on the aria-label rather than the CSS class: the `<style>` block ships unconditionally,
        // so `.cb-mic` is in the document either way. Only the button itself is behind the config.
        Livewire::test(CommandBar::class)
            ->assertSee('Dictate a command')
            ->assertSee('Dictation language');

        config(['ai.voice.enabled' => false]);

        Livewire::test(CommandBar::class)
            ->assertDontSee('Dictate a command')
            ->assertDontSee('Dictation language');
    }

    /**
     * The locale list reaches both the picker and the Alpine default.
     *
     * `@js` inside a double-quoted `x-data` is a place HTML escaping bites — it must emit single quotes,
     * or the attribute closes early and every handler in the component silently stops existing.
     */
    public function test_the_dictation_locales_render_safely(): void
    {
        Livewire::test(CommandBar::class)
            ->assertSee("locale: 'en-PK'", escape: false)
            ->assertSee('اردو');
    }

    // ------------------------------------------------------------------ answering the question

    /**
     * **A question the user can answer.** Asking without offering was the fourth "nothing happened".
     *
     * "income 500000 in" resolves everything but the category — no chart names a category "income", they
     * name kinds of receipt — so the bar asked "What should this be filed under?" and stopped. There was
     * no way to answer it: the only route forward was to retype the whole command using a word the alias
     * table happened to know, which from the outside is indistinguishable from being ignored.
     */
    public function test_an_unmatched_category_offers_the_tenants_own_list(): void
    {
        $interpretation = $this->interpret('income 500000 in', ['transaction_type_code' => null]);

        $this->assertFalse($interpretation->isComplete());
        $this->assertSame(
            ['transaction_type_code' => 'What should this be filed under?'],
            $interpretation->unresolved,
            'the missing slot is named, not just described',
        );

        $choices = app(CommandInterpreter::class)->resolverFor($interpretation->resolverKey)
            ->choices('transaction_type_code');

        $this->assertArrayHasKey('rent', $choices);
        $this->assertStringContainsString('5700', $choices['rent'], 'the account code disambiguates same-named categories');
    }

    /** Picking one re-resolves the command and completes it, without retyping anything. */
    public function test_picking_a_category_completes_the_command(): void
    {
        $this->model->queue($this->reply(['transaction_type_code' => null, 'direction' => 'in']));

        Livewire::test(CommandBar::class)
            ->set('utterance', 'income 25000 in')
            ->call('interpret')
            ->assertSet('preview.complete', false)
            ->set('answers.transaction_type_code', 'rent')
            ->assertSet('preview.complete', true)
            ->assertSee('Money in')
            ->assertSee('Confirm');
    }

    /**
     * And the picked value is booked — not merely displayed.
     *
     * The answer goes back through `resolve()` rather than patching the proposal, so it is re-fetched
     * inside the tenant and validated exactly like a parsed one. This asserts the result reaches the
     * ledger, which is the only claim that matters.
     */
    public function test_a_picked_category_reaches_the_ledger(): void
    {
        $this->model->queue($this->reply(['transaction_type_code' => null, 'direction' => 'out', 'amount' => '9000']));

        Livewire::test(CommandBar::class)
            ->set('utterance', 'something 9000 out')
            ->call('interpret')
            ->set('answers.transaction_type_code', 'utilities')
            ->call('confirm');

        $entry = JournalEntry::latest('id')->firstOrFail();
        $utilities = TransactionType::byCode('utilities')->account;

        $this->assertSame(
            9000.0,
            (float) JournalEntryLine::where('journal_entry_id', $entry->id)
                ->where('account_id', $utilities->id)
                ->value('debit_amount'),
            'the chosen category is what got debited',
        );
    }

    /**
     * A value the resolver never offered is refused.
     *
     * A `<select>` is a browser control and its options are whatever the browser says they are, so the
     * value arriving here is user input rather than a menu choice however it was rendered — the same
     * reason §8 re-fetches ids that came back from the model.
     */
    public function test_a_category_that_was_not_offered_is_ignored(): void
    {
        $this->model->queue($this->reply(['transaction_type_code' => null]));

        Livewire::test(CommandBar::class)
            ->set('utterance', 'something 9000 out')
            ->call('interpret')
            ->set('answers.transaction_type_code', 'not-a-real-code')
            ->assertSet('preview.complete', false);
    }

    /** Direction gets a list too — the same dead end, and a worse consequence if it is retyped wrong. */
    public function test_a_missing_direction_offers_in_or_out(): void
    {
        $interpretation = $this->interpret('rent 25000', ['direction' => null]);

        $this->assertArrayHasKey('direction', $interpretation->unresolved);

        $choices = app(CommandInterpreter::class)->resolverFor($interpretation->resolverKey)->choices('direction');

        $this->assertSame(['in' => 'Money in — received', 'out' => 'Money out — paid'], $choices);
    }

    /** "How much?" is not a menu, so it stays a plain question with no list beside it. */
    public function test_a_slot_with_no_closed_list_is_still_just_a_question(): void
    {
        $interpretation = $this->interpret('rent out', ['amount' => null]);

        $this->assertContains('How much?', $interpretation->questions);
        $this->assertArrayNotHasKey('amount', $interpretation->unresolved);
    }

    // ------------------------------------------------------------------ §8: getting to it at all

    /**
     * **The regression that made the whole feature look broken.** ⌘J does not reach the page.
     *
     * It is a reserved browser shortcut on both platforms — Chrome and Firefox open Downloads with it,
     * dispatched from the native menu bar before the document sees the keystroke — so `.prevent` runs too
     * late to take it back. The bar shipped with that as its *only* affordance, which made it unreachable
     * and unreachable silently: no dialog, no error, nothing in the log.
     *
     * Asserted on the markup because that is where the bug lived. The component was correct throughout;
     * every test below this line already passed while the feature could not be opened by anybody.
     */
    public function test_the_hotkey_is_not_one_the_browser_owns(): void
    {
        $html = Livewire::test(CommandBar::class)->html();

        $this->assertStringContainsString("\$event.key === '/'", $html, '⌘/ must open the bar');
        $this->assertStringNotContainsString('keydown.window.meta.j.prevent', $html, '⌘J cannot be the way in');
    }

    /**
     * **The second half of "nothing happened": the dialog closed itself on Enter.**
     *
     * `showModal()` opens a `<dialog>` by setting an `open` attribute. Interpreting is a Livewire round
     * trip, Livewire morphs the response over the live DOM, and the server HTML carries no `open` — so the
     * morph removed the one the browser had set and the box vanished. The proposal was rendered perfectly,
     * into something no longer on screen.
     *
     * `.self` maps to Alpine morph's `childrenOnly()`: the element's own attributes are left alone and the
     * children still update. Plain `wire:ignore` would freeze the proposal, which is the one thing here
     * that has to change — so the modifier is asserted, not just the directive.
     *
     * PHPUnit does not morph anything, so presence of the attribute is the whole of what can be checked
     * here. It is still worth checking: the failure it guards against is invisible and looks like a
     * backend bug.
     */
    public function test_the_dialog_survives_a_livewire_round_trip(): void
    {
        $html = Livewire::test(CommandBar::class)->html();

        $this->assertMatchesRegularExpression(
            '/<dialog\b[^>]*\bwire:ignore\.self\b/',
            $html,
            'without wire:ignore.self the morph strips `open` and the dialog shuts on Enter',
        );
    }

    /**
     * **The third "nothing happened": Confirm rendered, in white, on white.**
     *
     * `rgb(var(--primary-600, 217 119 6))` is a Filament 3 idiom. Filament 5 defines its palette as
     * complete colour values (`oklch(...)`), so the wrapper produces `rgb(oklch(...))` — invalid, and the
     * browser drops the entire declaration. The button lost its background and kept `color: #fff`.
     *
     * The declared fallback did not help, which is the subtle part: `var(--x, fallback)` uses the fallback
     * only when the variable is **undefined**. It was defined and the wrong shape, so nothing caught it.
     *
     * Asserted as an absence across the whole rendered output rather than on one selector, because the
     * same idiom had been copied into three other views and each one failed in the same silent way.
     */
    public function test_the_confirm_button_has_a_colour_the_browser_accepts(): void
    {
        $html = Livewire::test(CommandBar::class)->html();

        $this->assertStringContainsString('background: var(--primary-600', $html, 'the variable is used bare');
        $this->assertDoesNotMatchRegularExpression(
            '/rgba?\(\s*var\(--primary/',
            $html,
            'wrapping a Filament 5 colour variable in rgb() voids the declaration silently',
        );
    }

    /** And a button, because a shortcut is not discoverable and this one has no menu entry to discover. */
    public function test_the_floating_bubble_is_a_way_in(): void
    {
        $this->assertTrue(CommandBar::available());

        $html = Livewire::test(CommandBar::class)->html();

        $this->assertStringContainsString('cb-fab', $html);
        $this->assertStringContainsString('Type a transaction', $html);
        $this->assertStringContainsString('openBar()', $html);
    }

    /**
     * The dashboard box hands over rather than interpreting.
     *
     * The assertion that matters is the **absence**: it dispatches an event and nothing else. A second
     * surface that resolved its own commands would be a second place for §2's sign rules to live, and the
     * point of §2.1 is that there is exactly one. It also must not book on Enter — §6's gate is not
     * something an on-ramp gets to skip.
     */
    public function test_the_dashboard_box_hands_over_to_the_bar(): void
    {
        $html = view('filament.pages.command-input')->render();

        $this->assertStringContainsString('open-command-bar', $html, 'it opens the one bar');
        $this->assertStringContainsString('detail: { text:', $html, 'carrying what was typed');
        $this->assertStringNotContainsString('wire:', $html, 'it is not a Livewire component of its own');
    }

    /**
     * Switched off, every way in disappears with it.
     *
     * All three surfaces ask the same predicate rather than each deciding for itself, because the ways
     * they can disagree are the failure modes this section exists for: a control that opens nothing, and
     * a dialog with no control.
     */
    public function test_switching_the_feature_off_removes_every_way_in(): void
    {
        config(['ai.enabled' => false]);

        $this->assertFalse(CommandBar::available());

        $html = Livewire::test(CommandBar::class)->html();

        $this->assertStringNotContainsString('cb-dialog"', $html, 'the dialog goes');
        $this->assertStringNotContainsString('cb-fab"', $html, 'and so does the bubble');
        $this->assertStringNotContainsString('open-command-bar', view('filament.pages.command-input')->render());
    }

    // ------------------------------------------------------------------ Phase 2: Urdu

    /** Aliases render into the prompt, so the model matches a real category instead of returning null. */
    public function test_aliases_reach_the_prompt(): void
    {
        $this->seed(TransactionTypeAliasSeeder::class);

        $this->interpret('kiraya 25000 diya');

        $system = $this->model->calls[0]['system'];

        $this->assertStringContainsString('kiraya', $system, 'roman-Urdu alias must be offered to the model');
        $this->assertStringContainsString('کرایہ', $system, 'and the Urdu-script spelling too');
        $this->assertStringContainsString('rent:', $system);
    }

    /** Aliases are keyed on the category id, so renaming the category keeps every one of them — §3.2. */
    public function test_renaming_a_category_keeps_its_aliases(): void
    {
        $this->seed(TransactionTypeAliasSeeder::class);

        $rent = TransactionType::byCode('rent');
        $before = $rent->aliases()->count();
        $this->assertGreaterThan(0, $before);

        $rent->update(['name' => 'Office Rent']);

        $this->assertSame($before, $rent->refresh()->aliases()->count());
    }

    /** Deleting a category takes its aliases with it rather than leaving them pointing at nothing. */
    public function test_deleting_a_category_takes_its_aliases(): void
    {
        $this->seed(TransactionTypeAliasSeeder::class);

        $rent = TransactionType::byCode('rent');
        $this->assertGreaterThan(0, TransactionTypeAlias::where('transaction_type_id', $rent->id)->count());

        $rent->delete();

        $this->assertSame(0, TransactionTypeAlias::where('transaction_type_id', $rent->id)->count());
    }

    /** Aliases are lower-cased with mb_strtolower — the ASCII version leaves Urdu untouched. */
    public function test_aliases_are_normalised_on_the_way_in(): void
    {
        $rent = TransactionType::byCode('rent');

        $alias = TransactionTypeAlias::create(['transaction_type_id' => $rent->id, 'alias' => '  KIRAYA  ']);

        $this->assertSame('kiraya', $alias->alias);
    }

    /** Seeding aliases twice adds nothing the second time, and never invents a category. */
    public function test_the_alias_seeder_is_additive_and_idempotent(): void
    {
        $this->seed(TransactionTypeAliasSeeder::class);
        $first = TransactionTypeAlias::count();

        $this->seed(TransactionTypeAliasSeeder::class);

        $this->assertSame($first, TransactionTypeAlias::count());
        $this->assertSame(
            0,
            TransactionTypeAlias::whereNotIn('transaction_type_id', TransactionType::pluck('id'))->count(),
            'an alias must never point at a category the seeder created',
        );
    }

    /** The `kal` rule reaches the model — it means both yesterday and tomorrow, so it must be told. */
    public function test_the_prompt_states_the_kal_rule(): void
    {
        $this->interpret('rent 25000 out');

        $system = $this->model->calls[0]['system'];

        $this->assertStringContainsString('kal', $system);
        $this->assertStringContainsString('YESTERDAY', $system, 'the default must be stated, not implied');
        $this->assertStringContainsString('ambiguity', $system, 'and it must be flagged when tense does not settle it');
    }

    /** A `kal` the model resolved by default comes back flagged, so the date gets looked at. */
    public function test_a_defaulted_kal_is_flagged_for_the_user(): void
    {
        $interpretation = $this->interpret('kal kiraya 25000 diya', [
            'date' => now()->subDay()->toDateString(),
            'ambiguity' => ['date'],
        ]);

        $this->assertTrue($interpretation->date->isYesterday());
        $this->assertContains('date', $interpretation->flags);
        $this->assertTrue($interpretation->needsExplicitConfirmation(), 'a guessed date must not be Enter-confirmable');
    }

    /** The Urdu direction words are unambiguous in both directions and carry no confirmation friction. */
    public function test_urdu_direction_words_map_as_the_rules_require(): void
    {
        foreach (['aaya', 'mila', 'wasool', 'آیا', 'ملا', 'وصول'] as $word) {
            $this->assertSame(CommandGrammar::DIRECTION_IN, CommandGrammar::DIRECTION_WORDS[$word], $word);
        }

        foreach (['gaya', 'diya', 'kharch', 'گیا', 'دیا', 'خرچ'] as $word) {
            $this->assertSame(CommandGrammar::DIRECTION_OUT, CommandGrammar::DIRECTION_WORDS[$word], $word);
        }
    }

    /** A whole command in Urdu books the same entry an English one does. */
    public function test_an_urdu_command_books_the_same_entry(): void
    {
        $this->seed(TransactionTypeAliasSeeder::class);

        $interpretation = $this->interpret('کرایہ ۲۵ ہزار دیا', [
            'direction' => 'out',
            'amount' => '۲۵ ہزار',
            'transaction_type_code' => 'rent',
        ]);

        $this->assertEquals(25000.0, $interpretation->amount, 'Urdu digits and an Urdu scale word');

        $entry = app(CommandBooker::class)->book($interpretation);

        $cash = $interpretation->registerAccount;
        $cashLine = JournalEntryLine::where('journal_entry_id', $entry->id)->where('account_id', $cash->id)->firstOrFail();

        $this->assertEquals(25000, $cashLine->credit_amount, 'money out credits cash, whatever language said so');
    }

    /** Both halves of the requested vocabulary are in the grammar, mapped as the rules require. */
    public function test_the_requested_vocabulary_maps_as_specified(): void
    {
        foreach (['in', 'add', 'jama', 'debit'] as $word) {
            $this->assertSame(CommandGrammar::DIRECTION_IN, CommandGrammar::DIRECTION_WORDS[$word], $word);
        }

        foreach (['out', 'minus', 'less', 'credit'] as $word) {
            $this->assertSame(CommandGrammar::DIRECTION_OUT, CommandGrammar::DIRECTION_WORDS[$word], $word);
        }
    }
}
