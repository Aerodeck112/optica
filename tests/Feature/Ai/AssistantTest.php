<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Models\AiUsageDaily;
use App\Modules\Ai\Services\ClaudeGateway;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\SlotHold;
use App\Modules\Crm\Models\Customer;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingFixtures;
use Tests\Support\FakeClaudeGateway;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private FakeClaudeGateway $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSchedule('10:00', '22:00');
        $this->setting('booking.slot_interval_minutes', 60);
        $this->setting('booking.min_lead_minutes', 0);
        $this->setting('booking.auto_confirm', true);
        $this->setting('ai.enabled', true);
        $this->setting('ai.daily_budget_usd', '5');
        $this->publishPolicies();
        $this->withCredentials(); // cererile JSON trimit cookie-urile (sesiunea), ca fetch(..., {credentials: 'same-origin'})
        $this->claude = new FakeClaudeGateway;
        $this->app->instance(ClaudeGateway::class, $this->claude);
    }

    private ?string $sessionCookie = null;

    /** Retrimite cookie-ul de sesiune între cereri, ca un browser. */
    private function chat(string $message, ?string $conversation = null)
    {
        return $this->keepSession($this->postJson('/api/chat', array_filter(['message' => $message, 'conversation_id' => $conversation])));
    }

    private function confirmSummary(string $conversation, string $summaryId)
    {
        return $this->keepSession($this->postJson('/api/chat/confirm', ['conversation_id' => $conversation, 'summary_id' => $summaryId]));
    }

    private function keepSession($response)
    {
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie) {
            $this->sessionCookie = $cookie->getValue();
            $this->withCookie(config('session.cookie'), $this->sessionCookie);
        }

        return $response;
    }

    private function bookingInput(int $packageId, string $date, string $time): array
    {
        return ['package_id' => $packageId, 'date' => $date, 'time' => $time, 'participants' => 4, 'contact_name' => 'Ioana Pop', 'contact_email' => 'ioana@example.test', 'contact_phone' => '',
            'children_under_12' => 0, 'teens_12_17' => 0, 'adults' => 4, 'notes' => ''];
    }

    public function test_assistant_is_unavailable_without_configured_integration(): void
    {
        $this->app->forgetInstance(ClaudeGateway::class);
        $this->app->bind(ClaudeGateway::class, ClaudeGateway::class);

        $this->chat('Salut')->assertStatus(503)->assertJsonPath('status', 'unavailable')->assertJsonPath('handoff', true);
    }

    public function test_booking_is_created_only_after_explicit_confirmation(): void
    {
        $package = $this->package($this->arena());
        $date = LocalTime::now()->addDays(3)->toDateString();
        $this->claude->responses = [
            FakeClaudeGateway::toolUse('check_availability', ['package_id' => $package->id, 'date' => $date, 'participants' => 4], 't1'),
            FakeClaudeGateway::toolUse('prepare_booking', $this->bookingInput($package->id, $date, '15:00'), 't2'),
            FakeClaudeGateway::text('Verifică rezumatul și confirmă.'),
        ];

        $response = $this->chat('Vreau o sesiune pentru 4 persoane la ora 15')->assertOk();

        $summary = $response->json('messages.0.summary');
        $this->assertNotNull($summary);
        $this->assertSame(0, Booking::query()->count(), 'Asistentul nu creează rezervarea fără confirmare.');
        $this->assertSame(1, SlotHold::query()->whereNull('released_at')->count(), 'Ora este reținută temporar.');

        $conversation = $response->json('conversation_id');
        $this->confirmSummary($conversation, $summary['id'])->assertOk()->assertJsonStructure(['booking_code']);
        $booking = Booking::query()->sole();
        $this->assertSame('ai', $booking->source);
        $this->assertSame('confirmed', $booking->status);
        $this->assertNotEmpty($booking->accepted_policies);

        $this->confirmSummary($conversation, $summary['id'])->assertOk();
        $this->assertSame(1, Booking::query()->count(), 'Un rezumat se poate confirma o singură dată.');
    }

    public function test_assistant_cannot_prepare_a_slot_that_is_not_available(): void
    {
        $package = $this->package($this->arena());
        $date = LocalTime::now()->addDays(3)->toDateString();
        $this->claude->responses = [
            FakeClaudeGateway::toolUse('prepare_booking', $this->bookingInput($package->id, $date, '23:30')),
            FakeClaudeGateway::text('Ora nu este disponibilă.'),
        ];

        $response = $this->chat('Rezervă la 23:30')->assertOk();

        $this->assertNull($response->json('messages.0.summary'));
        $toolResult = json_decode($this->claude->requests[1]['messages'][2]['content'][0]['content'], true);
        $this->assertSame('slot_unavailable', $toolResult['reason']);
    }

    public function test_existing_booking_details_require_matching_contact(): void
    {
        $customer = Customer::factory()->create();
        $booking = Booking::query()->create(['code' => 'AWTESTAB1', 'package_id' => $this->package($this->arena())->id, 'customer_id' => $customer->id, 'participants' => 3, 'status' => 'confirmed', 'contact_name' => 'Altcineva', 'contact_email' => 'real@example.test', 'starts_at' => now()->addDays(2)]);
        $this->claude->responses = [
            FakeClaudeGateway::toolUse('find_my_booking', ['booking_code' => $booking->code, 'email_or_phone' => 'atacator@example.test']),
            FakeClaudeGateway::text('Nu am găsit.'),
        ];

        $this->chat('Care e rezervarea AWTESTAB1?')->assertOk();

        $result = json_decode($this->claude->requests[1]['messages'][2]['content'][0]['content'], true);
        $this->assertFalse($result['found']);
        $this->assertArrayNotHasKey('when', $result);
    }

    public function test_conversation_cannot_be_continued_from_another_session(): void
    {
        $this->claude->responses = [FakeClaudeGateway::text('Salut!')];
        $id = $this->chat('Salut')->json('conversation_id');

        $this->withCookie(config('session.cookie'), 'sesiune-straina');
        $this->claude->responses = [FakeClaudeGateway::text('Salut din nou!')];
        $other = $this->postJson('/api/chat', ['message' => 'Ce am vorbit?', 'conversation_id' => $id])->json('conversation_id');

        $this->assertNotSame($id, $other);
        $this->postJson('/api/chat/confirm', ['conversation_id' => $id, 'summary_id' => str_repeat('a', 16)])->assertNotFound();
    }

    public function test_daily_budget_stops_the_assistant(): void
    {
        AiUsageDaily::query()->create(['day' => LocalTime::now()->toDateString(), 'requests' => 100, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_micro_usd' => 5_000_000]);

        $this->chat('Salut')->assertStatus(503);
        $this->assertSame([], $this->claude->requests);
    }

    public function test_history_is_resent_unchanged_and_usage_is_recorded(): void
    {
        $this->claude->responses = [FakeClaudeGateway::toolUse('list_games', ['audience' => 'any']), FakeClaudeGateway::text('Avem jocuri.')];
        $id = $this->chat('Ce jocuri aveți?')->json('conversation_id');
        $this->claude->responses = [FakeClaudeGateway::text('Desigur.')];
        $this->chat('Și pentru copii?', $id);

        $first = $this->claude->requests[1]['messages'];
        $second = $this->claude->requests[2]['messages'];
        $this->assertSame($first, array_slice($second, 0, count($first)), 'Istoricul anterior se retrimite identic (doar se adaugă).');
        $this->assertSame('sig-toolu_1', $second[1]['content'][0]['signature'], 'Blocurile de gândire se păstrează.');
        $this->assertSame(3, (int) AiUsageDaily::query()->value('requests'));
    }
}
