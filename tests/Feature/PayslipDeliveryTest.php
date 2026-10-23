<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Tables\PayslipsTable;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipDeliveryService;
use App\Notifications\PayslipIssued;
use App\Support\WhatsApp\CloudApiWhatsAppSender;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Sending a payslip to the employee: email with the PDF attached, and the same
 * PDF on WhatsApp.
 *
 * Sent when payroll releases the month, never on creation. A payslip is
 * recalculated on every save and corrected after it is first cut, so sending
 * automatically would post somebody three copies of a figure that was wrong
 * twice — `sent_at` is what makes the release deliberate and a repeat visible.
 */
class PayslipDeliveryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payroll@test.local'));
        $this->setCurrentTenant();
    }

    /**
     * Resolved per call, not held from setUp(): the service takes its WhatsApp
     * sender by constructor, so a test that swaps the sender has to be able to do
     * so before the service is built.
     */
    private function delivery(): PayslipDeliveryService
    {
        return app(PayslipDeliveryService::class);
    }

    private function payslip(array $employee = [], array $settings = []): Payslip
    {
        $user = $this->makeUser('Employee', 'ayesha@test.local');
        $user->update(['name' => 'Ayesha Khan']);

        $record = Employee::create(array_merge([
            'user_id' => $user->id,
            'employee_id' => 'EMP-1',
            'gender' => 'Female',
            'phone' => '0300-1234567',
        ], $employee));

        EmployeeSetting::create(array_merge([
            'employee_id' => $record->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ], $settings));

        return Payslip::create([
            'employee_id' => $record->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    // --- email ---------------------------------------------------------------

    public function test_the_employee_is_emailed_their_payslip(): void
    {
        Notification::fake();

        $payslip = $this->payslip();

        $result = $this->delivery()->send($payslip);

        $this->assertSame('ayesha@test.local', $result['email']);
        $this->assertTrue($result['sent']);

        Notification::assertSentTo(
            $payslip->employee->user,
            PayslipIssued::class,
            fn (PayslipIssued $notification): bool => $notification->payslip->is($payslip),
        );
    }

    /**
     * The attachment is the point of the email, so it is asserted on the built
     * message rather than trusted to the notification class.
     */
    public function test_the_pdf_is_attached_to_the_email(): void
    {
        $payslip = $this->payslip();

        $mail = (new PayslipIssued($payslip))->toMail($payslip->employee->user);

        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame('EMP-1-July-'.$this->fiscalYear->name.'.pdf', $mail->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
        $this->assertStringContainsString('July', $mail->subject);
    }

    /** Cooks and drivers have no company mailbox; their own address is the point. */
    public function test_the_personal_address_is_used_when_there_is_no_company_one(): void
    {
        Notification::fake();

        $payslip = $this->payslip(['personal_email' => 'ayesha.personal@gmail.com']);
        $payslip->employee->user->update(['email' => '']);

        $result = $this->delivery()->send($payslip->fresh());

        $this->assertSame('ayesha.personal@gmail.com', $result['email']);
    }

    public function test_no_address_anywhere_is_reported_not_swallowed(): void
    {
        Notification::fake();

        $payslip = $this->payslip();
        $payslip->employee->user->update(['email' => '']);

        $result = $this->delivery()->send($payslip->fresh());

        $this->assertNull($result['email']);
        $this->assertContains('No email address on the employee record.', $result['errors']);
    }

    // --- whatsapp ------------------------------------------------------------

    public function test_the_payslip_goes_out_on_whatsapp_as_a_document(): void
    {
        $sent = [];

        $this->swap(WhatsAppSender::class, new class($sent) implements WhatsAppSender
        {
            public function __construct(public array &$sent) {}

            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                $this->sent[] = [
                    'to' => $to,
                    'filename' => $document->filename,
                    'caption' => $caption,
                    'bytes' => substr($document->bytes(), 0, 4),
                    'url' => $document->hasUrl() ? $document->url() : null,
                ];

                return 'wamid.TEST';
            }
        });

        $payslip = $this->payslip();

        $result = $this->delivery()->send($payslip);

        $this->assertSame('wamid.TEST', $result['whatsapp']);
        $this->assertSame('923001234567', $this->sentTo());
        $this->assertSame('%PDF', $this->lastSend()['bytes'], 'the PDF itself, not a link to one');
        $this->assertStringContainsString('July', $this->lastSend()['caption']);
    }

    public function test_a_number_whatsapp_cannot_reach_is_reported(): void
    {
        Notification::fake();

        $payslip = $this->payslip(['phone' => '12']);

        $result = $this->delivery()->send($payslip);

        $this->assertNull($result['whatsapp']);
        $this->assertContains(
            'The phone number on the employee record is not a number WhatsApp can reach.',
            $result['errors'],
        );
    }

    /** One channel failing must not take the other down with it. */
    public function test_the_email_still_goes_when_whatsapp_refuses(): void
    {
        Notification::fake();

        $this->swap(WhatsAppSender::class, new class implements WhatsAppSender
        {
            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                throw new WhatsAppException('Template payslip_issued is still in review');
            }
        });

        $result = $this->delivery()->send($this->payslip());

        $this->assertNotNull($result['email']);
        $this->assertNull($result['whatsapp']);
        $this->assertTrue($result['sent'], 'the payslip did reach the employee');
        $this->assertStringContainsString('still in review', implode(' ', $result['errors']));
    }

    // --- sending once --------------------------------------------------------

    public function test_sending_records_when_it_went(): void
    {
        Notification::fake();

        $payslip = $this->payslip();

        $this->assertFalse($payslip->wasSent());

        $this->delivery()->send($payslip);

        $this->assertTrue($payslip->fresh()->wasSent());
    }

    public function test_a_payslip_already_sent_is_not_sent_again_by_accident(): void
    {
        Notification::fake();

        $payslip = $this->payslip();
        $this->delivery()->send($payslip);

        $this->expectException(InvalidArgumentException::class);

        $this->delivery()->send($payslip->fresh());
    }

    public function test_it_can_be_sent_again_deliberately(): void
    {
        Notification::fake();

        $payslip = $this->payslip();
        $this->delivery()->send($payslip);

        $result = $this->delivery()->send($payslip->fresh(), resend: true);

        $this->assertTrue($result['sent']);
    }

    /**
     * A payslip nobody received must not read as sent: the month would look done
     * and nobody would chase it.
     */
    public function test_nothing_is_stamped_when_nothing_reached_anybody(): void
    {
        Notification::fake();

        $payslip = $this->payslip(['phone' => null]);
        $payslip->employee->user->update(['email' => '']);

        $result = $this->delivery()->send($payslip->fresh());

        $this->assertFalse($result['sent']);
        $this->assertFalse($payslip->fresh()->wasSent());
        $this->assertCount(2, $result['errors']);
    }

    public function test_a_send_is_recorded_in_the_audit_log(): void
    {
        Notification::fake();

        $payslip = $this->payslip();
        $this->delivery()->send($payslip);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Payslip',
            'description' => 'Payslip sent to the employee',
        ]);
    }

    // --- per company ---------------------------------------------------------

    public function test_a_company_can_send_by_email_only(): void
    {
        Notification::fake();

        app(\App\Support\TenantSettings::class)->set('payroll.payslip.send.whatsapp', false);

        $this->swap(WhatsAppSender::class, new class implements WhatsAppSender
        {
            public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
            {
                throw new \LogicException('WhatsApp must not be used when it is switched off');
            }
        });

        $result = $this->delivery()->send($this->payslip());

        $this->assertNotNull($result['email']);
        $this->assertNull($result['whatsapp']);
    }

    // --- the Cloud API itself ------------------------------------------------

    /**
     * Two calls, in order: upload the PDF for a media id, then send it. Meta has
     * no single call that takes a file and a recipient, and uploading is what
     * avoids putting a payslip on an unauthenticated URL for them to fetch.
     */
    public function test_the_cloud_api_uploads_the_pdf_then_sends_it(): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-1']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.ABC']]]),
        ]);

        $sender = new CloudApiWhatsAppSender('PHONE-ID', 'TOKEN', 'v21.0');

        $id = $sender->sendDocument('923001234567', $this->document(), 'Payslip for July');

        $this->assertSame('wamid.ABC', $id);

        Http::assertSentInOrder([
            fn ($request): bool => str_ends_with($request->url(), '/PHONE-ID/media'),
            function ($request): bool {
                $body = $request->data();

                return str_ends_with($request->url(), '/PHONE-ID/messages')
                    && $body['type'] === 'document'
                    && $body['to'] === '923001234567'
                    && $body['document']['id'] === 'MEDIA-1'
                    && $body['document']['filename'] === 'payslip.pdf';
            },
        ]);
    }

    /**
     * With a template configured the message is a template message with the PDF in
     * its header — the only shape WhatsApp accepts for a business writing first,
     * which payroll always is.
     */
    public function test_a_configured_template_is_used_with_the_pdf_in_its_header(): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-2']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.DEF']]]),
        ]);

        $sender = new CloudApiWhatsAppSender('PHONE-ID', 'TOKEN', 'v21.0', 'payslip_issued', 'en_US');

        $sender->sendDocument('923001234567', $this->document(), 'Payslip for July 2026');

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/PHONE-ID/messages')) {
                return false;
            }

            $body = $request->data();

            return $body['type'] === 'template'
                && $body['template']['name'] === 'payslip_issued'
                && $body['template']['language']['code'] === 'en_US'
                && $body['template']['components'][0]['parameters'][0]['document']['id'] === 'MEDIA-2'
                && $body['template']['components'][1]['parameters'][0]['text'] === 'Payslip for July 2026';
        });
    }

    /** Meta's own words: three different failures need three different fixes. */
    public function test_what_meta_says_about_a_refusal_is_kept(): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-3']),
            'graph.facebook.com/*/messages' => Http::response([
                'error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030],
            ], 400),
        ]);

        $sender = new CloudApiWhatsAppSender('PHONE-ID', 'TOKEN');

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('Recipient phone number not in allowed list');

        $sender->sendDocument('923001234567', $this->document(), 'Payslip');
    }

    // --- numbers -------------------------------------------------------------

    public function test_local_numbers_become_e164(): void
    {
        // Stored as they are dialled locally; WhatsApp takes only E.164.
        $this->assertSame('923001234567', PhoneNumber::e164('0300-1234567'));
        $this->assertSame('923001234567', PhoneNumber::e164('+92 300 1234567'));
        $this->assertSame('923001234567', PhoneNumber::e164('0092-300-1234567'));
        $this->assertSame('923001234567', PhoneNumber::e164('300 123 4567'));
        $this->assertSame('971501234567', PhoneNumber::e164('+971 50 123 4567'));

        // Nothing usable: reported to the sender rather than guessed at, because a
        // guess is how a payslip reaches a stranger.
        $this->assertNull(PhoneNumber::e164(null));
        $this->assertNull(PhoneNumber::e164(''));
        $this->assertNull(PhoneNumber::e164('n/a'));
        $this->assertNull(PhoneNumber::e164('123'));
    }

    // --- the panel -----------------------------------------------------------

    public function test_the_row_action_says_where_it_is_about_to_send(): void
    {
        $payslip = $this->payslip();

        $summary = (new \ReflectionMethod(PayslipsTable::class, 'destinationSummary'));
        $summary->setAccessible(true);

        $this->assertStringContainsString('ayesha@test.local', $summary->invoke(null, $payslip));
        $this->assertStringContainsString('+923001234567', $summary->invoke(null, $payslip));
    }

    /** A stand-in payslip document for the tests that exercise a driver directly. */
    private function document(?string $url = 'https://payroll.test/whatsapp-media/acme/payslip/1/payslip.pdf'): WhatsAppDocument
    {
        return new WhatsAppDocument(
            filename: 'payslip.pdf',
            bytes: fn (): string => '%PDF-1.4 fake',
            url: $url === null ? null : fn (): string => $url,
        );
    }

    /** @return array<string, mixed> */
    private function lastSend(): array
    {
        $sender = app(WhatsAppSender::class);

        return end($sender->sent) ?: [];
    }

    private function sentTo(): ?string
    {
        return $this->lastSend()['to'] ?? null;
    }
}
