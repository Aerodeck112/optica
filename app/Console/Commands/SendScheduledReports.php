<?php

namespace App\Console\Commands;

use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportTables;
use App\Domain\Reports\ReportWriter;
use App\Jobs\ReportFilterDescriber;
use App\Models\ReportSubscription;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Daily, weekly (Mondays) and monthly (1st) reports by email to the recipients configured for each
 * subscription (RAP-05), with the rights of its author. Nothing is sent without a configured
 * email account.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled {--force : send now regardless of the day}';

    protected $description = 'Emails the scheduled reports that are due today';

    public function handle(ReportTables $tables, ReportWriter $writer, ReportFilterDescriber $describer, ChannelRegistry $channels): int
    {
        $today = CarbonImmutable::now(config('optica.clinic_timezone'))->startOfDay();
        foreach (ReportSubscription::with('creator')->where('is_active', true)->get() as $subscription) {
            $due = $this->option('force') || match ($subscription->frequency) {
                'daily' => true,
                'weekly' => $today->isMonday(),
                'monthly' => $today->day === 1,
                default => false,
            };
            if (! $due || ($subscription->last_sent_at && $subscription->last_sent_at->setTimezone(config('optica.clinic_timezone'))->isSameDay($today) && ! $this->option('force'))) {
                continue;
            }
            $result = $this->send($subscription, $today, $tables, $writer, $describer, $channels);
            $subscription->forceFill(['last_sent_at' => now(), 'last_result' => mb_substr($result, 0, 300)])->save();
            $this->line($subscription->name.': '.$result);
        }

        return self::SUCCESS;
    }

    private function send(ReportSubscription $s, CarbonImmutable $today, ReportTables $tables, ReportWriter $writer, ReportFilterDescriber $describer, ChannelRegistry $channels): string
    {
        $user = $s->creator;
        if (! $user || ! $user->can('reports.export') || (in_array($s->report, ReportTables::FINANCIAL, true) && ! $user->can('reports.financial'))) {
            return 'forbidden';
        }
        $account = $channels->accountFor('email', null);
        if (! $account || $account->driver !== 'smtp') {
            return __('messages.reasons.not_configured', ['channel' => 'Email'], 'ro');
        }
        // The previous complete day, week or month.
        [$from, $to] = match ($s->frequency) {
            'weekly' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()],
            'monthly' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            default => [$today->subDay(), $today->subDay()->endOfDay()],
        };
        $filters = ReportFilters::fromArray(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'compare' => 'none'] + $s->filters);
        $path = tempnam(sys_get_temp_dir(), 'rep');
        try {
            $table = $tables->build($s->report, $filters, $user, $s->locale);
            $writer->write($table, $s->format, $path, $describer->lines($filters, $s->locale), $s->locale);
            $transport = new EsmtpTransport((string) $account->credential('host'), (int) $account->credential('port'), $account->credential('encryption') === 'ssl' ? true : null);
            $transport->setUsername((string) $account->credential('username'));
            $transport->setPassword((string) $account->credential('password'));
            $email = (new Email)->from(new Address((string) ($account->sender ?: $account->credential('username')), 'Optica Optofarm'))
                ->to(...array_map(fn ($r) => new Address($r), $s->recipients))
                ->subject($s->name.' – '.$from->format('d.m.Y').($from->isSameDay($to) ? '' : ' – '.$to->format('d.m.Y')))
                ->text(__('reports.email_body', ['name' => $s->name], $s->locale))
                ->attachFromPath($path, \Illuminate\Support\Str::slug($s->name).'.'.$s->format);
            (new Mailer($transport))->send($email);
            app(AuditLogger::class)->log('reports.scheduled_sent', $s, ['recipients' => count($s->recipients), 'rows' => count($table['rows'])], $user);

            return 'sent';
        } catch (Throwable $e) {
            report($e);

            return 'failed: '.$e->getMessage();
        } finally {
            @unlink($path);
        }
    }
}
