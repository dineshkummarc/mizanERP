<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\CommandUtterance;
use App\Modules\Accounting\Services\CommandBooker;
use App\Modules\Accounting\Services\CommandInterpreter;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Quotations\Models\Quotation;
use App\Support\Ai\FakeStructuredModel;
use App\Support\Ai\StructuredModel;
use Database\Seeders\TransactionTypeSeeder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * "invoice Acme 40 hours at 5000" and "quote Acme …" — `docs/bexio-gap-plan.md` Phase 4, item 15.
 *
 * The model is faked; what is asserted is routing, the arithmetic on the lines, the questions when a slot
 * is missing, and that confirmation writes a draft and nothing else.
 */
class DocumentCommandResolversTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private FakeStructuredModel $model;

    private Contact $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'commands@test.local'));
        $this->setCurrentTenant();
        $this->seed(TransactionTypeSeeder::class);

        config(['ai.enabled' => true]);

        $this->model = new FakeStructuredModel;
        $this->app->instance(StructuredModel::class, $this->model);

        $this->acme = Contact::create(['name' => 'Acme Textiles', 'kind' => Contact::KIND_CUSTOMER, 'payment_terms_days' => 30]);
    }

    /** @param array<string, mixed> $overrides */
    private function reply(array $overrides = []): array
    {
        return $overrides + [
            'contact_id' => $this->acme->getKey(),
            'lines' => [['description' => 'Consultancy', 'quantity' => '40', 'unit_price' => '5,000']],
            'date' => null,
            'description' => null,
            'confidence' => 0.9,
            'ambiguity' => [],
        ];
    }

    public function test_an_invoice_command_drafts_an_invoice_with_its_lines(): void
    {
        $this->model->queue($this->reply());

        $interpretation = app(CommandInterpreter::class)->interpret('invoice Acme 40 hours of consultancy at 5000');

        $this->assertSame('invoice', $interpretation->resolverKey);
        $this->assertTrue($interpretation->isComplete());
        $this->assertSame(200_000.0, $interpretation->amount);
        $this->assertStringContainsString('Draft invoice — 200,000.00 to Acme Textiles, 1 line, not issued', $interpretation->effectLine());

        $invoice = app(CommandBooker::class)->book($interpretation);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status, 'issuing stays a person\'s act');
        $this->assertSame(Invoice::KIND_SALE, $invoice->kind);
        $this->assertTrue($invoice->contact->is($this->acme));
        $this->assertSame(200_000.0, (float) $invoice->total);
        $this->assertNotNull($invoice->due_date, 'the customer\'s terms fill the due date');

        $line = $invoice->lines->first();
        $this->assertSame('Consultancy', $line->description);
        $this->assertSame(40.0, (float) $line->quantity);
        $this->assertSame(5000.0, (float) $line->unit_price);
        $this->assertSame(200_000.0, (float) $line->line_total);

        $this->assertSame(CommandUtterance::OUTCOME_BOOKED, $interpretation->utterance->fresh()->outcome);
    }

    public function test_a_quote_command_drafts_a_quotation(): void
    {
        $this->model->queue($this->reply());

        $interpretation = app(CommandInterpreter::class)->interpret('quote Acme 40 hours at 5000');

        $this->assertSame('quotation', $interpretation->resolverKey);
        $this->assertStringContainsString('Draft quote — 200,000.00 for Acme Textiles', $interpretation->effectLine());

        $quote = app(CommandBooker::class)->book($interpretation);

        $this->assertInstanceOf(Quotation::class, $quote);
        $this->assertTrue($quote->isDraft(), 'Send on its row is what fixes and emails it');
        $this->assertSame(200_000.0, (float) $quote->total);
        $this->assertSame(1, $quote->lines()->count());
    }

    /** The prompt carries only the resolver's own data: customers, not employees or categories. */
    public function test_the_prompt_lists_customers(): void
    {
        $this->model->queue($this->reply());

        app(CommandInterpreter::class)->interpret('invoice Acme 1 at 100');

        $this->assertStringContainsString('Acme Textiles', $this->model->calls[0]['system']);
        $this->assertStringContainsString('LINES.', $this->model->calls[0]['system']);
    }

    public function test_an_unnamed_customer_is_asked_for_from_a_list(): void
    {
        $this->model->queue($this->reply(['contact_id' => null]));

        $interpretation = app(CommandInterpreter::class)->interpret('invoice 40 hours at 5000');

        $this->assertFalse($interpretation->isComplete());
        $this->assertContains('Who is it for?', $interpretation->questions);
        $this->assertArrayHasKey('contact_id', $interpretation->unresolved);

        $choices = app(CommandInterpreter::class)->resolverFor('invoice')->choices('contact_id');
        $this->assertSame('Acme Textiles', $choices[$this->acme->getKey()]);
    }

    public function test_a_line_with_no_price_is_asked_about(): void
    {
        $this->model->queue($this->reply(['lines' => [['description' => 'Design', 'quantity' => '1', 'unit_price' => null]]]));

        $interpretation = app(CommandInterpreter::class)->interpret('invoice Acme for the design');

        $this->assertFalse($interpretation->isComplete());
        $this->assertContains('How much for "Design"?', $interpretation->questions);
    }

    public function test_a_command_that_names_nothing_to_bill_is_asked(): void
    {
        $this->model->queue($this->reply(['lines' => []]));

        $interpretation = app(CommandInterpreter::class)->interpret('invoice Acme');

        $this->assertContains('What is being billed?', $interpretation->questions);
    }

    public function test_the_proposal_survives_the_confirmation_round_trip(): void
    {
        $this->model->queue($this->reply());

        $first = app(CommandInterpreter::class)->interpret('invoice Acme 40 hours at 5000');
        $again = app(CommandInterpreter::class)->resolverFor('invoice')->rehydrate($first->utterance->fresh());

        $this->assertNotNull($again);
        $this->assertTrue($again->isComplete());
        $this->assertSame(200_000.0, $again->amount);
        $this->assertSame($first->effectLine(), $again->effectLine());
    }

    /** A cash command still goes to the cash register, and a quote never becomes an invoice. */
    public function test_routing_leaves_the_other_resolvers_alone(): void
    {
        $this->model->queue(['direction' => 'out', 'amount' => '25000', 'transaction_type_code' => 'rent', 'date' => null, 'description' => null, 'confidence' => 1, 'ambiguity' => []]);

        $this->assertSame('cash', app(CommandInterpreter::class)->interpret('rent 25000 out')->resolverKey);
    }
}
