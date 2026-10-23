<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipDeliveryService;
use App\Modules\Payroll\Support\PayslipMediaLink;
use App\Support\WhatsApp\TwilioWhatsAppSender;
use App\Support\WhatsApp\WhatsAppDocument;
use App\Support\WhatsApp\WhatsAppException;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Sending the payslip through Twilio, per
 * https://www.twilio.com/docs/whatsapp/guidance-whatsapp-media-messages.
 *
 * Twilio does not carry the file: it is given a `MediaUrl` and collects the
 * document itself. Everything below follows from that — the signed link it
 * fetches, the content-type it validates before accepting the message, and the
 * filename that has to live in the URL because "Twilio does not support setting a
 * filename or caption for documents".
 */
class PayslipWhatsAppTwilioTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private \App\Modules\Core\Models\Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payroll@test.local'));
        $this->company = $this->setCurrentTenant();

        config([
            'whatsapp.driver' => 'twilio',
            'whatsapp.twilio.account_sid' => 'AC123',
            'whatsapp.twilio.auth_token' => 'secret-token',
            'whatsapp.twilio.from' => '+14155238886',
        ]);

        $this->app->forgetInstance(WhatsAppSender::class);
    }

    private int $employees = 0;

    private function payslip(array $employee = []): Payslip
    {
        $n = ++$this->employees;

        $user = $this->makeUser('Employee', "employee-{$n}@test.local");
        $user->update(['name' => "Employee {$n}"]);

        $record = Employee::create(array_merge([
            'user_id' => $user->id,
            'employee_id' => "EMP-{$n}",
            'gender' => 'Female',
            'phone' => '0300-1234567',
        ], $employee));

        EmployeeSetting::create([
            'employee_id' => $record->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);

        return Payslip::create([
            'employee_id' => $record->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    private function document(?string $url = 'https://payroll.test/media/payslip.pdf'): WhatsAppDocument
    {
        return new WhatsAppDocument(
            filename: 'payslip.pdf',
            bytes: fn (): string => '%PDF-1.4 fake',
            url: $url === null ? null : fn (): string => $url,
        );
    }

    // --- the driver ----------------------------------------------------------

    public function test_the_document_is_sent_as_a_media_url_on_the_whatsapp_channel(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'])]);

        $sender = new TwilioWhatsAppSender('AC123', 'secret-token', '+14155238886');

        $sid = $sender->sendDocument('923001234567', $this->document(), 'Payslip for July 2026');

        $this->assertSame('SM123', $sid);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
                // Both ends carry the channel prefix. A bare +number here is an
                // SMS — the wrong channel, silently, and billed for.
                && $body['From'] === 'whatsapp:+14155238886'
                && $body['To'] === 'whatsapp:+923001234567'
                && $body['MediaUrl'] === 'https://payroll.test/media/payslip.pdf'
                // The caption travels as the message body: Twilio cannot set one
                // on the document itself.
                && $body['Body'] === 'Payslip for July 2026';
        });
    }

    public function test_it_authenticates_as_the_twilio_account(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM124'])]);

        (new TwilioWhatsAppSender('AC123', 'secret-token', '+14155238886'))
            ->sendDocument('923001234567', $this->document(), 'Payslip');

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('AC123:secret-token'),
        ));
    }

    /** A "from" already carrying the prefix is left as configured. */
    public function test_a_prefixed_sender_number_is_not_prefixed_twice(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM125'])]);

        (new TwilioWhatsAppSender('AC123', 'secret-token', 'whatsapp:+14155238886'))
            ->sendDocument('923001234567', $this->document(), 'Payslip');

        Http::assertSent(fn ($request): bool => $request->data()['From'] === 'whatsapp:+14155238886');
    }

    /**
     * Twilio's own words and code. 63016 (no template, outside the 24-hour window)
     * and 11200 (Twilio could not fetch the MediaUrl) need unrelated fixes.
     */
    public function test_what_twilio_says_about_a_refusal_is_kept(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response([
            'message' => 'Failed to send freeform message because you are outside the allowed window',
            'code' => 63016,
        ], 400)]);

        $sender = new TwilioWhatsAppSender('AC123', 'secret-token', '+14155238886');

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('outside the allowed window');

        $sender->sendDocument('923001234567', $this->document(), 'Payslip');
    }

    /** Nothing is sent with no link to fetch: a message with no media is not one. */
    public function test_a_document_with_no_url_is_refused_rather_than_sent_empty(): void
    {
        Http::fake();

        $sender = new TwilioWhatsAppSender('AC123', 'secret-token', '+14155238886');

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('sends media by link');

        $sender->sendDocument('923001234567', $this->document(url: null), 'Payslip');

        Http::assertNothingSent();
    }

    public function test_a_file_over_twilios_limit_is_reported_before_sending(): void
    {
        $huge = new WhatsAppDocument(
            filename: 'payslip.pdf',
            bytes: fn (): string => str_repeat('x', TwilioWhatsAppSender::MAX_BYTES + 1),
            url: fn (): string => 'https://payroll.test/media/payslip.pdf',
        );

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('capped at 20MB');

        TwilioWhatsAppSender::assertSendable($huge);
    }

    // --- the Content Template path -------------------------------------------

    /**
     * The path any real payroll takes. WhatsApp only lets a business open a
     * conversation with an approved template, and payroll is never inside the
     * 24-hour window that would allow a freeform message.
     */
    public function test_a_configured_template_is_sent_as_contentsid_and_variables(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM300'])]);

        $sender = new TwilioWhatsAppSender(
            'AC123', 'secret-token', '+14155238886',
            contentSid: 'HX'.str_repeat('a', 32),
            templateMediaBase: 'https://payroll.test',
        );

        $sender->sendDocument(
            '923001234567',
            $this->document('https://payroll.test/whatsapp-media/acme/payslip/7/1800000000/'.str_repeat('b', 64).'/EMP-1-July.pdf'),
            'Payslip for July 2026',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            // ContentSid "cannot be combined with Body or MediaUrl" — so neither
            // is sent, and the document reaches the message as a variable.
            return $body['ContentSid'] === 'HX'.str_repeat('a', 32)
                && ! array_key_exists('Body', $body)
                && ! array_key_exists('MediaUrl', $body)
                && $body['To'] === 'whatsapp:+923001234567'
                && json_decode($body['ContentVariables'], true) === [
                    // Everything after the domain the template holds: "variables
                    // are only supported after the domain".
                    '1' => 'whatsapp-media/acme/payslip/7/1800000000/'.str_repeat('b', 64).'/EMP-1-July.pdf',
                    '2' => 'Payslip for July 2026',
                ];
        });
    }

    /**
     * A template pointing at one host while the app generates links on another
     * makes Twilio fetch the two concatenated. The only symptom is a message that
     * never arrives, so it is refused here with the reason.
     */
    public function test_a_link_outside_the_templates_media_domain_is_refused(): void
    {
        Http::fake();

        $sender = new TwilioWhatsAppSender(
            'AC123', 'secret-token', '+14155238886',
            contentSid: 'HX'.str_repeat('a', 32),
            templateMediaBase: 'https://payroll.test',
        );

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('does not sit under the media domain');

        $sender->sendDocument('923001234567', $this->document('https://somewhere-else.test/media/payslip.pdf'), 'Payslip');
    }

    public function test_a_template_without_its_media_domain_configured_is_refused(): void
    {
        Http::fake();

        $sender = new TwilioWhatsAppSender(
            'AC123', 'secret-token', '+14155238886',
            contentSid: 'HX'.str_repeat('a', 32),
        );

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('template_media_base');

        $sender->sendDocument('923001234567', $this->document(), 'Payslip');
    }

    /** End to end, with the app's own link: what production will actually post. */
    public function test_sending_a_payslip_through_the_template_carries_its_own_link(): void
    {
        Notification::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM400'])]);

        config([
            'whatsapp.twilio.content_sid' => 'HX'.str_repeat('c', 32),
            'whatsapp.twilio.template_media_base' => config('app.url'),
        ]);
        $this->app->forgetInstance(WhatsAppSender::class);

        $payslip = $this->payslip();

        $result = app(PayslipDeliveryService::class)->send($payslip);

        $this->assertSame('SM400', $result['whatsapp']);

        Http::assertSent(function ($request) use ($payslip): bool {
            $variables = json_decode($request->data()['ContentVariables'], true);

            return str_starts_with($variables['1'], "whatsapp-media/{$this->company->slug}/payslip/{$payslip->getKey()}/")
                // A relative path, so the template's domain and this join cleanly.
                && ! str_starts_with($variables['1'], 'http')
                && str_contains($variables['2'], 'July');
        });
    }

    /** And the link it hands over is one the route actually serves. */
    public function test_the_link_in_the_template_variable_resolves(): void
    {
        Notification::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM401'])]);

        config([
            'whatsapp.twilio.content_sid' => 'HX'.str_repeat('c', 32),
            'whatsapp.twilio.template_media_base' => config('app.url'),
        ]);
        $this->app->forgetInstance(WhatsAppSender::class);

        app(PayslipDeliveryService::class)->send($this->payslip());

        $path = json_decode(Http::recorded()[0][0]->data()['ContentVariables'], true)['1'];

        $response = $this->get(rtrim((string) config('app.url'), '/').'/'.$path)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // --- the link Twilio fetches ---------------------------------------------

    public function test_sending_hands_twilio_a_signed_link_to_this_payslip(): void
    {
        Notification::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM200'])]);

        $payslip = $this->payslip();

        $result = app(PayslipDeliveryService::class)->send($payslip);

        $this->assertSame('SM200', $result['whatsapp']);

        Http::assertSent(function ($request) use ($payslip): bool {
            $url = $request->data()['MediaUrl'];

            return str_contains($url, "/whatsapp-media/{$this->company->slug}/payslip/{$payslip->getKey()}/")
                // The filename is the last segment because Twilio cannot set one
                // on the document; this is what the recipient's phone shows.
                && str_ends_with($url, '.pdf')
                // Expiry and signature are path segments, not a query string —
                // a template variable substitutes only after the domain, so a
                // query string could not survive being passed as one.
                && ! str_contains($url, '?')
                && preg_match('#/payslip/\d+/\d+/[a-f0-9]{64}/#', $url) === 1;
        });
    }

    /**
     * The one unauthenticated path to somebody's salary, so the assertions here
     * are about what it refuses.
     */
    public function test_the_link_serves_the_pdf_with_the_content_type_twilio_validates(): void
    {
        $payslip = $this->payslip();

        $response = $this->get($this->mediaUrl($payslip))->assertOk();

        // "Twilio checks the content-type header at the provided MediaUrl to
        // validate the content type of the media file. If the content-type header
        // does not match that of the media file, Twilio rejects the request."
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_link_with_a_forged_signature_is_refused(): void
    {
        $payslip = $this->payslip();

        $this->get(url(sprintf(
            'whatsapp-media/%s/payslip/%d/%d/%s/payslip.pdf',
            $this->company->slug,
            $payslip->getKey(),
            now()->addMinutes(10)->getTimestamp(),
            str_repeat('a', 64),
        )))->assertForbidden();
    }

    /** Editing the payslip id in a valid link must not fetch somebody else's. */
    public function test_a_tampered_link_is_refused(): void
    {
        $mine = $this->payslip();
        $theirs = $this->payslip();

        $tampered = str_replace(
            "/payslip/{$mine->getKey()}/",
            "/payslip/{$theirs->getKey()}/",
            $this->mediaUrl($mine),
        );

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $payslip = $this->payslip();
        $url = $this->mediaUrl($payslip);

        $this->travel(config('whatsapp.media_url_ttl') + 1)->minutes();

        $this->get($url)->assertForbidden();
    }

    /**
     * The expiry is in the path and signed with everything else, so pushing it out
     * is not something a holder of the link can do.
     */
    public function test_the_expiry_cannot_be_extended(): void
    {
        $payslip = $this->payslip();
        $url = $this->mediaUrl($payslip);

        $extended = preg_replace_callback(
            '#/payslip/(\d+)/(\d+)/#',
            fn (array $m): string => "/payslip/{$m[1]}/".(now()->addYear()->getTimestamp()).'/',
            $url,
        );

        $this->get($extended)->assertForbidden();
    }

    /**
     * The company is signed over too, so an unknown one can only appear in a link
     * somebody minted — which is why this is a 404 and not a 403.
     */
    public function test_a_link_naming_a_company_that_does_not_exist_is_not_found(): void
    {
        Company::factory()->create(['slug' => 'other-co']);

        $payslip = $this->payslip();

        $this->get(PayslipMediaLink::for($payslip, $this->company, 'payslip.pdf', 10))->assertOk();

        // Signed for a company that has since been removed.
        $ghost = Company::factory()->make(['slug' => 'no-such-company']);
        $ghost->id = 9999;

        $this->get(PayslipMediaLink::for($payslip, $ghost, 'payslip.pdf', 10))->assertNotFound();
    }

    private function mediaUrl(Payslip $payslip): string
    {
        return PayslipMediaLink::for(
            $payslip,
            $this->company,
            'payslip.pdf',
            (int) config('whatsapp.media_url_ttl'),
        );
    }
}
