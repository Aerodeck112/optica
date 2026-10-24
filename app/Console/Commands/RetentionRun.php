<?php

namespace App\Console\Commands;

use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Aplică perioadele de păstrare setate în panou (Setări → Retenție date). Documentele fiscale nu sunt atinse.
 */
#[Signature('aw:retention {--dry-run : Afișează ce s-ar șterge, fără modificări}')]
#[Description('Șterge/anonimizează datele care au depășit perioada de păstrare')]
class RetentionRun extends Command
{
    public function handle(SettingsRepository $settings, CustomerDataService $data): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = [];

        $report['message_logs'] = $this->purge(DB::table('message_logs')->where('created_at', '<', now()->subDays($settings->int('retention.message_logs_days', 365))), $dry);
        $report['audit_logs'] = $this->purge(DB::table('audit_logs')->where('created_at', '<', now()->subDays($settings->int('retention.audit_days', 730))), $dry);
        $report['ai_conversations'] = $this->purge(DB::table('ai_conversations')->where('last_activity_at', '<', now()->subDays($settings->int('ai.retention_days', 30))), $dry);
        $report['slot_holds'] = $this->purge(DB::table('slot_holds')->where('expires_at', '<', now()->subDay()), $dry);
        $report['login_tokens'] = $this->purge(DB::table('customer_login_tokens')->where('expires_at', '<', now()->subDay()), $dry);
        $report['webhook_events'] = $this->purge(DB::table('webhook_events')->where('created_at', '<', now()->subDays(90))->where('status', 'processed'), $dry);

        $lostLeads = DB::table('leads')->where('stage', 'lost')->where('stage_changed_at', '<', now()->subDays($settings->int('retention.lost_leads_days', 365)))->whereNotNull('contact_email');
        $report['lost_leads_anonymized'] = $dry ? $lostLeads->count() : $lostLeads->update(['contact_name' => 'Cerere anonimizată', 'contact_email' => null, 'contact_phone' => null, 'message' => null]);

        $months = $settings->int('retention.inactive_customers_months', 36);
        $inactive = Customer::query()->whereNull('anonymized_at')->whereNull('merged_into_id')
            ->where(fn ($q) => $q->where('last_booking_at', '<', now()->subMonths($months))->orWhere(fn ($q) => $q->whereNull('last_booking_at')->where('created_at', '<', now()->subMonths($months))))
            ->whereDoesntHave('consents', fn ($q) => $q->where('status', 'granted'));
        $count = 0;
        foreach ($inactive->cursor() as $customer) {
            if ($data->blockers($customer) === []) {
                $count++;
                if (! $dry) {
                    $data->anonymize($customer, null, 'Retenție automată: fără activitate de '.$months.' luni și fără consimțământ de marketing activ');
                }
            }
        }
        $report['inactive_customers_anonymized'] = $count;

        $oldExports = collect(Storage::disk('local')->files('exports'))->filter(fn ($f) => Storage::disk('local')->lastModified($f) < now()->subDays(30)->getTimestamp());
        if (! $dry) {
            Storage::disk('local')->delete($oldExports->all());
        }
        $report['export_files'] = $oldExports->count();

        foreach ($report as $key => $value) {
            $this->line(($dry ? '[simulare] ' : '')."{$key}: {$value}");
        }

        return self::SUCCESS;
    }

    private function purge($query, bool $dry): int
    {
        return $dry ? $query->count() : $query->delete();
    }
}
