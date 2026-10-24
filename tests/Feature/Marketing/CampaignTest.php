<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use App\Modules\Crm\Models\Consent;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignRecipient;
use App\Modules\Marketing\Models\ContactImport;
use App\Modules\Marketing\Models\Segment;
use App\Modules\Marketing\Services\CampaignService;
use App\Modules\Marketing\Services\ContactImportService;
use App\Modules\Marketing\Services\SegmentQuery;
use App\Modules\Marketing\Services\ShortUnsubscribeLink;
use App\Modules\Notifications\Models\MessageLog;
use App\Support\ContactNormalizer;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        // în afara orelor de liniște implicite (20:00–09:00, ora României)
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Europe/Bucharest'));
    }

    private function subscriber(string $channel = 'email'): Customer
    {
        $customer = Customer::factory()->create(['phone' => '0740'.random_int(100000, 999999)]);
        $customer->forceFill(['phone_normalized' => ContactNormalizer::phone($customer->phone)])->save();
        app(ConsentService::class)->grant($customer, $channel, 'booking_form');

        return $customer;
    }

    private function campaign(string $channel = 'email', array $attributes = []): Campaign
    {
        $segment = Segment::query()->create(['name' => 'Toți abonații', 'rules' => []]);

        return Campaign::query()->create($attributes + [
            'name' => 'Test', 'channel' => $channel, 'segment_id' => $segment->id, 'locale' => 'ro',
            'subject' => 'Noutăți', 'body' => 'Un joc nou te așteaptă.', 'status' => 'draft', 'test_sent_at' => now(),
        ]);
    }

    private function marketer(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->assignRole('marketing'));
    }

    public function test_segment_contains_only_customers_with_consent_on_that_channel(): void
    {
        $emailOnly = $this->subscriber('email');
        Customer::factory()->create();

        $ids = SegmentQuery::build([], 'email')->pluck('id')->all();

        $this->assertSame([$emailOnly->id], $ids);
        $this->assertSame([], SegmentQuery::build([], 'sms')->pluck('id')->all());
    }

    public function test_customer_who_unsubscribes_after_scheduling_receives_nothing(): void
    {
        $stays = $this->subscriber();
        $leaves = $this->subscriber();
        $campaign = app(CampaignService::class)->schedule($this->campaign(), CarbonImmutable::now()->addHour(), $this->marketer());

        app(ConsentService::class)->withdraw($leaves, 'email', 'unsubscribe_link');
        $this->travel(2)->hours();
        app(CampaignService::class)->dispatchDue();

        $this->assertSame('sent', $campaign->refresh()->status);
        $this->assertSame(1, MessageLog::query()->where('campaign_id', $campaign->id)->where('customer_id', $stays->id)->where('status', 'queued')->count());
        $this->assertSame(0, MessageLog::query()->where('campaign_id', $campaign->id)->where('customer_id', $leaves->id)->where('status', 'queued')->count());
    }

    public function test_frequency_cap_skips_recently_contacted_customers(): void
    {
        $this->setting('marketing.frequency_cap_count', 1);
        $customer = $this->subscriber();
        $first = app(CampaignService::class)->schedule($this->campaign(), CarbonImmutable::now(), $this->marketer());
        app(CampaignService::class)->dispatchDue();

        $second = app(CampaignService::class)->schedule($this->campaign(), CarbonImmutable::now(), $this->marketer());
        app(CampaignService::class)->dispatchDue();

        $this->assertSame('sent', CampaignRecipient::query()->where('campaign_id', $first->id)->value('status'));
        $this->assertSame('frequency_cap', CampaignRecipient::query()->where('campaign_id', $second->id)->value('skip_reason'));
    }

    public function test_sms_campaign_cannot_be_scheduled_above_cost_cap(): void
    {
        $this->setting('marketing.sms_cost_per_segment_micro', 50000); // 0,05 lei / segment
        foreach (range(1, 3) as $i) {
            $this->subscriber('sms');
        }
        $campaign = $this->campaign('sms', ['cost_cap_minor' => 10, 'subject' => null]); // plafon 0,10 lei

        $this->expectException(RuntimeException::class);
        app(CampaignService::class)->schedule($campaign, CarbonImmutable::now(), $this->marketer());
    }

    public function test_nothing_is_sent_during_quiet_hours(): void
    {
        $this->subscriber();
        $campaign = app(CampaignService::class)->schedule($this->campaign(), CarbonImmutable::now(), $this->marketer());
        $this->travelTo(CarbonImmutable::parse('2026-11-10 22:30', 'Europe/Bucharest'));

        $this->assertSame(0, app(CampaignService::class)->dispatchDue());
        $this->assertSame('scheduled', $campaign->refresh()->status);
    }

    public function test_scheduling_requires_a_test_send_and_send_permission(): void
    {
        $this->subscriber();
        $editor = tap(User::factory()->create(), fn ($u) => $u->assignRole('editor'));

        try {
            app(CampaignService::class)->schedule($this->campaign(), CarbonImmutable::now(), $editor);
            $this->fail('Editorul nu poate programa campanii.');
        } catch (RuntimeException) {
        }
        $this->expectException(RuntimeException::class);
        app(CampaignService::class)->schedule($this->campaign('email', ['test_sent_at' => null]), CarbonImmutable::now(), $this->marketer());
    }

    public function test_import_grants_consent_only_for_rows_with_explicit_dated_consent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "email;first_name;consent_email;consent_date\nda@example.test;Ana;da;2025-05-01\nnu@example.test;Ion;;\nfara-data@example.test;Eva;da;\n");
        $import = ContactImport::query()->create(['filename' => 'test.csv', 'source_description' => 'Formular hârtie recepție', 'permission_proof' => 'Formulare semnate, dosar 2025', 'channels' => ['email'], 'status' => 'processing']);

        app(ContactImportService::class)->run($import, $path);

        $this->assertSame(3, $import->refresh()->rows_imported);
        $this->assertSame(1, Consent::query()->where('status', 'granted')->count());
        $this->assertSame('da@example.test', Consent::query()->first()->customer->email);
    }

    public function test_short_unsubscribe_link_is_signed(): void
    {
        $customer = $this->subscriber('sms');
        $token = basename(ShortUnsubscribeLink::url($customer));

        $this->assertSame([$customer->id, 'sms'], ShortUnsubscribeLink::resolve($token));
        $this->assertNull(ShortUnsubscribeLink::resolve(substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a')));
        $this->get('/u/'.$token)->assertRedirect();
    }
}
