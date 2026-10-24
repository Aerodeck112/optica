<?php

namespace Tests\Feature\Notifications;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Core\Models\Integration;
use App\Modules\Notifications\Models\MessageLog;
use App\Modules\Notifications\Models\ScheduledNotification;
use App\Modules\Notifications\Services\ScheduledDispatcher;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\NotificationTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $this->seed(BaseDataSeeder::class);
        $this->seed(NotificationTemplatesSeeder::class);
        $this->seedSchedule();
        $this->setting('booking.auto_confirm', true);
        $this->setting('booking.min_lead_minutes', 60);
        $this->publishPolicies();

        // integrare de e-mail „live” — dar mediul de test forțează sandbox
        $integration = Integration::query()->firstOrNew(['key' => 'email']);
        $integration->forceFill(['driver' => 'brevo', 'mode' => 'live', 'status' => 'configured', 'options' => ['from_email' => 'rezervari@example.test']]);
        $integration->storeCredentials(['api_key' => 'cheie-fictiva']);
        $integration->save();
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<msg@brevo>'], 201)]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function book(string $email): Booking
    {
        return app(BookingService::class)->create(new BookingData(
            package: $this->package($this->arena()),
            startsAt: LocalTime::fromLocal('2026-10-22', '15:00'),
            participants: 2,
            contactName: 'Client Fictiv',
            contactEmail: $email,
            contactPhone: '0700000000',
            source: 'web',
            acceptedPolicyTypes: ['terms', 'booking_policy'],
            ageGroups: ['adults' => 2],
        ))['booking'];
    }

    public function test_customers_get_no_real_messages_outside_production(): void
    {
        config(['aw.messaging.force_sandbox' => true, 'aw.messaging.test_recipients_email' => []]);

        $booking = $this->book('client-real@example.test');

        $log = MessageLog::query()->where('customer_id', $booking->customer_id)->first();
        $this->assertNotNull($log, 'Confirmarea este generată și înregistrată în jurnal.');
        $this->assertSame('sandboxed', $log->status);
        Http::assertNothingSent();
    }

    public function test_test_recipients_receive_real_messages_in_sandbox_mode(): void
    {
        config(['aw.messaging.force_sandbox' => true, 'aw.messaging.test_recipients_email' => ['echipa-test@example.test']]);

        $booking = $this->book('echipa-test@example.test');

        $this->assertSame('accepted', MessageLog::query()->where('customer_id', $booking->customer_id)->value('status'));
        Http::assertSent(fn ($request) => $request['to'][0]['email'] === 'echipa-test@example.test');
    }

    public function test_reminders_follow_reschedule_and_cancellation_and_are_sent_once(): void
    {
        config(['aw.messaging.force_sandbox' => true]);
        $booking = $this->book('client@example.test');
        $pending = fn () => ScheduledNotification::query()->where('booking_id', $booking->id)->where('status', 'pending')->orderBy('send_at')->get();

        $this->assertSame(['booking.reminder_long', 'booking.reminder_short'], $pending()->pluck('template_key')->all());
        $this->assertTrue($pending()->first()->send_at->equalTo($booking->starts_at->subHours(24)));

        app(BookingService::class)->reschedule($booking, LocalTime::fromLocal('2026-10-23', '16:00'), 'user', null, false);
        $this->assertTrue($pending()->first()->send_at->equalTo(LocalTime::fromLocal('2026-10-23', '16:00')->subHours(24)), 'Reminderele se recalculează la reprogramare.');

        $this->travelTo(LocalTime::fromLocal('2026-10-22', '17:00'));
        $this->assertSame(1, app(ScheduledDispatcher::class)->run());
        $this->assertSame(0, app(ScheduledDispatcher::class)->run(), 'Un reminder nu se trimite de două ori.');

        app(BookingService::class)->cancel($booking->fresh(), 'test', 'user');
        $this->assertCount(0, $pending(), 'La anulare, reminderele rămase se opresc.');
    }
}
