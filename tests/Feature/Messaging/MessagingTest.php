<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\CampaignService;
use App\Domain\Messaging\MessageSender;
use App\Domain\Messaging\SmsSegments;
use App\Domain\Messaging\TemplateRenderer;
use App\Domain\Orders\OrderService;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Consent;
use App\Models\Message;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Support\Consent\ConsentRecorder;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    private const TZ = 'Europe/Bucharest';

    protected function setUp(): void
    {
        parent::setUp();
        // Midday, outside the default quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', self::TZ));
        Settings::put('communications.quiet_hours', ['start' => '21:00', 'end' => '08:00']);
    }

    private function sms(): ChannelAccount
    {
        return ChannelAccount::create(['channel' => 'sms', 'driver' => 'twilio', 'name' => 'SMS', 'sender' => 'OPTOFARM', 'is_active' => true,
            'credentials' => ['account_sid' => 'AC_TEST', 'auth_token' => 'secret-token'], 'estimated_unit_cost_cents' => 30]);
    }

    private function whatsapp(): ChannelAccount
    {
        return ChannelAccount::create(['channel' => 'whatsapp', 'driver' => 'whatsapp_cloud', 'name' => 'WA', 'sender' => '+40700000000', 'is_active' => true,
            'credentials' => ['phone_number_id' => '123', 'access_token' => 'tok', 'app_secret' => 'app-secret', 'verify_token' => 'verify-me']]);
    }

    private function templates(string $type, array $channels): void
    {
        foreach ($channels as $channel) {
            MessageTemplate::create(['type' => $type, 'channel' => $channel, 'locale' => 'ro',
                'body' => 'Bună ziua, {patient_name}! Programare la {location}, {date} ora {time}.',
                'provider_template' => $channel === 'whatsapp' ? 'programare_ro' : null,
                'provider_status' => $channel === 'whatsapp' ? 'approved' : 'not_required']);
        }
    }

    private function booking(array $overrides = [])
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $service = $this->makeService(['default_duration' => 30]);
        $offering = $this->makeOffering($location, $specialist, $service);
        $this->makeSchedule($location, $specialist, [1 => [['08:00', '18:00']], 2 => [['08:00', '18:00']], 3 => [['08:00', '18:00']]]);
        $patient = $this->makePatient(['phone' => '0744 123 456']);

        return app(BookingService::class)->book($offering, $overrides + [
            'starts_at' => CarbonImmutable::parse('2026-11-04 10:00', self::TZ),
            'source' => AppointmentSource::Reception,
            'patient_id' => $patient->id,
        ]);
    }

    public function test_placeholders_are_validated_and_sms_length_is_explained(): void
    {
        $this->assertSame([], TemplateRenderer::invalid('appointment_reminder', 'Vă așteptăm {date} la {time}.'));
        $this->assertSame(['diagnosis', '{…}'], TemplateRenderer::invalid('appointment_reminder', 'Rezultat {diagnosis} {date'));

        $plain = SmsSegments::analyse(str_repeat('a', 160));
        $this->assertSame(['GSM-7', 1], [$plain['encoding'], $plain['segments']]);
        $diacritics = SmsSegments::analyse(str_repeat('a', 69).'ș');
        $this->assertSame(['UCS-2', 70, 1], [$diacritics['encoding'], $diacritics['length'], $diacritics['segments']]);
        $this->assertSame(2, SmsSegments::analyse(str_repeat('ț', 71))['segments']);
        $this->assertSame('Va asteptam la sedinta', TemplateRenderer::ascii('Vă așteptăm la ședința'));
    }

    public function test_nothing_is_queued_or_sent_while_channels_are_not_configured(): void
    {
        MessageRule::create(['type' => 'appointment_confirmed', 'enabled' => true, 'channels' => ['sms']]);
        $this->templates('appointment_confirmed', ['sms']);
        Http::fake();

        $appointment = $this->booking();

        $message = Message::where('appointment_id', $appointment->id)->firstOrFail();
        $this->assertSame('skipped', $message->status);
        $this->assertStringContainsString('nu este configurat', $message->status_reason);
        app(MessageSender::class)->processDue();
        Http::assertNothingSent();
    }

    public function test_confirmation_is_sent_through_twilio_and_delivery_is_confirmed_by_signed_webhook(): void
    {
        $account = $this->sms();
        MessageRule::create(['type' => 'appointment_confirmed', 'enabled' => true, 'channels' => ['sms']]);
        $this->templates('appointment_confirmed', ['sms']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued', 'num_segments' => '1', 'price' => null], 201)]);

        $appointment = $this->booking();
        app(MessageSender::class)->processDue();

        $message = Message::where('appointment_id', $appointment->id)->firstOrFail();
        $this->assertSame('accepted', $message->status);
        $this->assertSame('+40744123456', $message->recipient);
        $this->assertStringContainsString('04.11.2026 ora 10:00', $message->body);
        Http::assertSent(fn ($r) => $r['To'] === '+40744123456' && $r['From'] === 'OPTOFARM' && str_contains($r->url(), 'AC_TEST'));

        // Unsigned callbacks are refused.
        $this->post(route('webhooks.twilio', $account), ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'])->assertForbidden();

        $params = ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered', 'Price' => '-0.04'];
        $this->post(route('webhooks.twilio', $account), $params, ['X-Twilio-Signature' => $this->twilioSignature(route('webhooks.twilio', $account), $params)])->assertOk();
        $this->post(route('webhooks.twilio', $account), $params, ['X-Twilio-Signature' => $this->twilioSignature(route('webhooks.twilio', $account), $params)])->assertOk();
        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertSame(4, $message->cost_reported_cents);
        // Diacritics make it UCS-2: the estimate follows the number of segments.
        $this->assertSame(30 * SmsSegments::analyse($message->body)['segments'], $message->cost_estimated_cents);

        // A late "sent" callback never moves a delivered message backwards.
        $late = ['MessageSid' => 'SM123', 'MessageStatus' => 'sent'];
        $this->post(route('webhooks.twilio', $account), $late, ['X-Twilio-Signature' => $this->twilioSignature(route('webhooks.twilio', $account), $late)]);
        $this->assertSame('delivered', $message->fresh()->status);
    }

    public function test_a_test_installation_only_messages_the_listed_test_recipients(): void
    {
        config(['optica.test_site' => true, 'optica.test_recipients' => ['0744 123 456']]);
        $this->sms();
        MessageRule::create(['type' => 'appointment_confirmed', 'enabled' => true, 'channels' => ['sms']]);
        $this->templates('appointment_confirmed', ['sms']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM900', 'status' => 'queued', 'num_segments' => '1'], 201)]);

        // The booking's phone (+40 744 123 456) is on the test list: it is sent.
        $listed = $this->booking();
        app(MessageSender::class)->processDue();
        $this->assertSame('accepted', Message::where('appointment_id', $listed->id)->value('status'));

        // Anybody else is skipped, never sent.
        config(['optica.test_recipients' => ['test@example.test']]);
        $other = $this->booking(['starts_at' => CarbonImmutable::parse('2026-11-04 11:00', 'Europe/Bucharest')]);
        app(MessageSender::class)->processDue();
        $message = Message::where('appointment_id', $other->id)->firstOrFail();
        $this->assertSame('skipped', $message->status);
        $this->assertSame('test_site', $message->status_reason);
        Http::assertSentCount(1);
    }

    public function test_definitive_failure_falls_back_once_and_timeouts_are_never_resent_automatically(): void
    {
        $this->whatsapp();
        $this->sms();
        MessageRule::create(['type' => 'appointment_confirmed', 'enabled' => true, 'channels' => ['whatsapp', 'sms']]);
        $this->templates('appointment_confirmed', ['whatsapp', 'sms']);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400),
            'api.twilio.com/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $appointment = $this->booking();
        app(MessageSender::class)->processDue();
        app(MessageSender::class)->processDue();
        app(MessageSender::class)->processDue();

        $messages = Message::where('appointment_id', $appointment->id)->orderBy('id')->get();
        $this->assertSame(['whatsapp', 'sms'], $messages->pluck('channel')->all());
        $this->assertSame(['failed', 'uncertain'], $messages->pluck('status')->all());
        $this->assertSame($messages[0]->id, $messages[1]->fallback_of_id);
        $this->assertSame(1, $messages[1]->attempts);
    }

    public function test_temporary_errors_are_retried_with_a_limit(): void
    {
        $this->sms();
        MessageRule::create(['type' => 'appointment_confirmed', 'enabled' => true, 'channels' => ['sms']]);
        $this->templates('appointment_confirmed', ['sms']);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'busy'], 503)]);
        $appointment = $this->booking();

        for ($i = 0; $i < 8; $i++) {
            app(MessageSender::class)->processDue();
            $this->travel(2)->hours();
        }
        $message = Message::where('appointment_id', $appointment->id)->firstOrFail();
        $this->assertSame('failed', $message->status);
        $this->assertSame(5, $message->attempts);
    }

    public function test_reminders_are_planned_and_withdrawn_when_the_appointment_changes(): void
    {
        $this->sms();
        MessageRule::create(['type' => 'appointment_reminder', 'enabled' => true, 'channels' => ['sms'], 'offset_hours' => 24]);
        $this->templates('appointment_reminder', ['sms']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM9', 'status' => 'queued'], 201)]);
        $appointment = $this->booking();

        // Too early: the reminder is planned only shortly before its own time.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', self::TZ));
        $this->artisan('messages:send')->assertSuccessful();
        $this->assertSame(0, Message::where('type', 'appointment_reminder')->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:55', self::TZ));
        $this->artisan('messages:send')->assertSuccessful();
        $reminder = Message::where('type', 'appointment_reminder')->firstOrFail();
        $this->assertSame('pending', $reminder->status);
        $this->assertSame('2026-11-03 10:00', $reminder->send_after->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->artisan('messages:send');
        $this->assertSame(1, Message::where('type', 'appointment_reminder')->count());

        app(BookingService::class)->reschedule($appointment->fresh(), CarbonImmutable::parse('2026-11-04 15:00', self::TZ), null, $this->staff('reception'));
        $this->assertSame('cancelled', $reminder->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:30', self::TZ));
        $this->artisan('messages:send');
        // Only the reminder for the new time goes out, once.
        $this->assertSame(1, Message::where('type', 'appointment_reminder')->where('status', '!=', 'cancelled')->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['Body'], '04.11.2026 ora 15:00'));
    }

    public function test_quiet_hours_delay_reminders(): void
    {
        $sender = app(MessageSender::class);
        $this->assertNull($sender->quietUntil(CarbonImmutable::parse('2026-11-02 12:00', self::TZ)));
        $this->assertSame('2026-11-03 08:00', $sender->quietUntil(CarbonImmutable::parse('2026-11-02 22:15', self::TZ))->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-03 08:00', $sender->quietUntil(CarbonImmutable::parse('2026-11-03 06:00', self::TZ))->setTimezone(self::TZ)->format('Y-m-d H:i'));
    }

    public function test_marketing_reaches_only_consenting_patients_and_rechecks_consent_before_sending(): void
    {
        $this->sms();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMX', 'status' => 'queued'], 201)]);
        $consents = app(ConsentRecorder::class);
        $yes = $this->makePatient(['phone' => '0744 000 001']);
        $withdrawn = $this->makePatient(['phone' => '0744 000 002']);
        $never = $this->makePatient(['phone' => '0744 000 003']);
        $consents->recordMarketingChoice($yes->id, $yes->phone, null, 'sms', true, 'reception');
        $consents->recordMarketingChoice($withdrawn->id, $withdrawn->phone, null, 'sms', true, 'reception');
        $consents->recordMarketingChoice($withdrawn->id, $withdrawn->phone, null, 'sms', false, 'reception');

        $campaign = Campaign::create(['name' => 'Toamnă DEMO', 'channel' => 'sms', 'segment' => [], 'content' => ['ro' => ['body' => 'Ofertă DEMO, {patient_name}. Răspundeți STOP pentru dezabonare.']]]);
        $service = app(CampaignService::class);
        $this->assertSame([$yes->id], $service->recipients($campaign)->pluck('id')->all());

        $service->start($campaign);
        // Withdrawal after queuing still wins.
        $consents->recordMarketingChoice($yes->id, $yes->phone, null, 'sms', false, 'reception');
        app(MessageSender::class)->processDue();

        $this->assertSame('skipped', Message::where('campaign_id', $campaign->id)->value('status'));
        Http::assertNothingSent();
        $this->assertNotContains($never->id, Message::pluck('patient_id')->all());
    }

    public function test_whatsapp_webhook_signature_status_and_stop_reply(): void
    {
        $account = $this->whatsapp();
        $this->get(route('webhooks.whatsapp.verify', $account).'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();
        $this->get(route('webhooks.whatsapp.verify', $account).'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')->assertOk()->assertSee('42');

        $message = Message::create(['uuid' => (string) Str::uuid(), 'type' => 'appointment_confirmed', 'category' => 'operational', 'channel' => 'whatsapp',
            'locale' => 'ro', 'recipient' => '+40744000001', 'body' => 'x', 'status' => 'accepted', 'provider_message_id' => 'wamid.1', 'send_after' => now()]);
        $payload = json_encode(['entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.1', 'status' => 'read', 'timestamp' => '1']],
            'messages' => [['from' => '40744000009', 'type' => 'text', 'text' => ['body' => 'STOP']]],
        ]]]]]]);
        $this->call('POST', route('webhooks.whatsapp', $account), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $payload)->assertForbidden();
        $this->call('POST', route('webhooks.whatsapp', $account), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'app-secret')], $payload)->assertNoContent();

        $this->assertSame('delivered', $message->fresh()->status);
        $this->assertTrue(Consent::where('contact_phone', '40744000009')->where('channel', 'whatsapp')->where('granted', false)->exists());
    }

    public function test_order_ready_message_carries_no_clinical_detail(): void
    {
        $this->sms();
        MessageRule::create(['type' => 'order_ready', 'enabled' => true, 'channels' => ['sms']]);
        MessageTemplate::create(['type' => 'order_ready', 'channel' => 'sms', 'locale' => 'hu', 'body' => 'Kedves {patient_name}! A(z) {order_number} rendelése átvehető: {location}.']);
        $patient = $this->makePatient(['locale' => 'hu', 'phone' => '0744 555 666']);
        $location = $this->makeLocation();
        $order = app(OrderService::class)->create($patient, ['location_id' => $location->id], [['kind' => 'frame', 'description' => 'Keret', 'quantity' => 1, 'unit_price_cents' => 100]], $this->staff('reception'));
        $user = $this->staff('reception');
        foreach (['lab', 'quality_check', 'ready'] as $status) {
            $order = app(OrderService::class)->changeStatus($order, $status, $user);
        }

        $message = Message::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('hu', $message->locale);
        $this->assertStringContainsString($order->number, $message->body);
    }

    private function twilioSignature(string $url, array $params): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, 'secret-token', true));
    }
}
