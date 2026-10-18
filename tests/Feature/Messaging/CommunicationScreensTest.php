<?php

namespace Tests\Feature\Messaging;

use App\Livewire\Admin\Communication\ChannelAccounts;
use App\Livewire\Admin\Communication\MessageRules;
use App\Livewire\Admin\Communication\MessageTemplates;
use App\Livewire\Crm\Campaigns;
use App\Livewire\Crm\MessageLog;
use App\Livewire\Crm\Patient\CommunicationsTab;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Message;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class CommunicationScreensTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    public function test_credentials_are_encrypted_never_shown_and_kept_when_left_empty(): void
    {
        $this->signIn($this->staff('superadmin'));
        Livewire::test(ChannelAccounts::class)->call('create', 'sms')
            ->set('form.sender', 'OPTOFARM')->set('form.is_active', true)
            ->set('secrets.account_sid', 'AC_SECRET_SID')->set('secrets.auth_token', 'very-secret-token')
            ->call('save')->assertHasNoErrors();
        $account = ChannelAccount::firstOrFail();
        $this->assertStringNotContainsString('very-secret-token', (string) \DB::table('channel_accounts')->value('credentials'));

        Livewire::test(ChannelAccounts::class)->call('edit', $account->id)->assertDontSee('very-secret-token')
            ->set('form.name', 'SMS principal')->call('save');
        $this->assertSame('very-secret-token', $account->fresh()->credential('auth_token'));
        $this->assertStringNotContainsString('very-secret-token', json_encode(\App\Models\AuditLog::pluck('meta')));

        $this->signIn($this->staff('marketing'));
        $this->get(route('admin.communication.channels'))->assertForbidden();
    }

    public function test_test_message_goes_only_to_the_typed_recipient_and_reports_the_real_result(): void
    {
        $this->signIn($this->staff('superadmin'));
        $account = ChannelAccount::create(['channel' => 'sms', 'driver' => 'twilio', 'name' => 'SMS', 'sender' => 'OPTOFARM', 'is_active' => true,
            'credentials' => ['account_sid' => 'AC1', 'auth_token' => 't']]);
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'Invalid To'], 400)]);

        Livewire::test(ChannelAccounts::class)->call('startTest', $account->id)->set('testTo', '0700 000 111')->call('sendTest')
            ->assertSet('notice', null)->assertSee('Invalid To');
        Http::assertSent(fn ($r) => $r['To'] === '+40700000111');
        $this->assertSame('failed', Message::where('category', 'test')->value('status'));
    }

    public function test_templates_refuse_unknown_variables_and_whatsapp_changes_need_new_approval(): void
    {
        $this->signIn($this->staff('marketing'));
        Livewire::test(MessageTemplates::class)->call('create')
            ->set('form.type', 'appointment_reminder')->set('form.channel', 'sms')
            ->set('form.body', 'Vă așteptăm {date} la {ora}.')->call('save')->assertHasErrors(['form.body']);

        $template = MessageTemplate::create(['type' => 'appointment_reminder', 'channel' => 'whatsapp', 'locale' => 'ro', 'body' => 'Mâine la {time}.',
            'provider_template' => 'reminder_ro', 'provider_status' => 'approved']);
        Livewire::test(MessageTemplates::class)->call('edit', $template->id)->set('form.body', 'Vă reamintim: {date}, ora {time}.')->call('save')->assertHasNoErrors();
        $this->assertSame('draft', $template->fresh()->provider_status);
        $this->assertFalse($template->fresh()->isUsable());
    }

    public function test_sms_preview_explains_segments(): void
    {
        $this->signIn($this->staff('marketing'));
        Livewire::test(MessageTemplates::class)->call('create')->set('form.channel', 'sms')
            ->set('form.body', 'Bună ziua, {patient_name}! Vă așteptăm la {location}, {date}, ora {time}. Pentru modificări: {manage_link}')
            ->assertSee('UCS-2')->set('form.ascii_only', true)->assertSee('GSM-7');
    }

    public function test_rules_and_quiet_hours_are_saved(): void
    {
        $this->signIn($this->staff('marketing'));
        Livewire::test(MessageRules::class)
            ->set('rules.appointment_reminder.enabled', true)->set('rules.appointment_reminder.c1', 'whatsapp')->set('rules.appointment_reminder.c2', 'sms')
            ->set('rules.appointment_reminder.offset', 48)->set('quietStart', '20:00')->set('quietEnd', '09:00')->call('save')->assertHasNoErrors();
        $rule = MessageRule::where('type', 'appointment_reminder')->whereNull('location_id')->firstOrFail();
        $this->assertSame(['whatsapp', 'sms'], $rule->channels);
        $this->assertSame(48, $rule->offset_hours);
        $this->assertSame(['start' => '20:00', 'end' => '09:00'], \App\Support\Settings::get('communications.quiet_hours'));
    }

    public function test_email_campaigns_need_an_unsubscribe_link_and_a_configured_channel(): void
    {
        $this->signIn($this->staff('marketing'));
        $component = Livewire::test(Campaigns::class)->call('create')->set('form.name', 'Toamnă DEMO')
            ->set('form.locales', ['ro'])->set('form.content.ro.subject', 'Oferte DEMO')->set('form.content.ro.body', 'Text DEMO pentru {patient_name}.')
            ->call('save')->assertHasErrors(['form.content.ro.body']);
        $component->set('form.content.ro.body', "Text DEMO pentru {patient_name}.\nDezabonare: {unsubscribe_link}")->call('save')->assertHasNoErrors()
            ->call('schedule')->assertSet('failure', __('messages.campaigns.channel_not_ready', ['channel' => 'Email']));
        $this->assertSame('draft', Campaign::firstOrFail()->status);

        $this->signIn($this->staff('reception'));
        $this->get(route('crm.campaigns'))->assertForbidden();
    }

    public function test_recall_is_scheduled_without_clinical_detail_and_failed_messages_are_resent_by_people_only(): void
    {
        MessageRule::create(['type' => 'recall', 'enabled' => true, 'channels' => ['sms']]);
        ChannelAccount::create(['channel' => 'sms', 'driver' => 'twilio', 'name' => 'SMS', 'sender' => 'OPTOFARM', 'is_active' => true, 'credentials' => ['account_sid' => 'AC1', 'auth_token' => 't']]);
        MessageTemplate::create(['type' => 'recall', 'channel' => 'sms', 'locale' => 'ro', 'body' => 'Bună ziua, {patient_name}! Este timpul pentru controlul periodic la {location}. Programări: {booking_link}']);
        $patient = $this->makePatient();
        $location = $this->makeLocation();
        $this->signIn($this->staff('reception', [], [$location->id]));

        Livewire::test(CommunicationsTab::class, ['patientId' => $patient->id])
            ->set('recallDate', now()->addMonths(6)->toDateString())->set('recallLocation', $location->id)->call('scheduleRecall')->assertHasNoErrors();
        $message = Message::where('type', 'recall')->firstOrFail();
        $this->assertSame('pending', $message->status);
        $this->assertSame('10:00', $message->send_after->timezone('Europe/Bucharest')->format('H:i'));

        $message->update(['status' => 'uncertain']);
        Livewire::test(MessageLog::class)->call('retry', $message->id);
        $this->assertSame(2, Message::count());
        $this->assertSame($message->id, Message::latest('id')->value('fallback_of_id'));

        $this->signIn($this->staff('marketing'));
        Livewire::test(MessageLog::class)->assertForbidden();
    }
}
