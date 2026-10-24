<?php

namespace App\Filament\Widgets;

use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Lead;
use App\Modules\Finance\Models\Payment;
use App\Support\LocalTime;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $today = LocalTime::now()->toDateString();
        $todayQuery = Booking::query()->onLocalDate($today)->whereIn('status', ['pending', 'confirmed', 'checked_in', 'completed']);
        $count = (clone $todayQuery)->count();
        $participants = (int) (clone $todayQuery)->sum('participants');
        $waiting = (clone $todayQuery)->whereIn('status', ['pending', 'confirmed'])->count();
        $requests = Booking::query()->where('status', 'request')->count() + Lead::query()->where('stage', 'new')->count();
        $pending = Booking::query()->where('status', 'pending')->count();
        $user = auth()->user();

        $stats = [
            Stat::make('Rezervări azi', (string) $count)->description("{$participants} participanți · {$waiting} încă nesosiți")->icon('heroicon-o-calendar'),
            Stat::make('Cereri de tratat', (string) $requests)->description('Cereri de rezervare și de evenimente noi')->icon('heroicon-o-inbox-stack')->color($requests ? 'warning' : 'success'),
            Stat::make('În așteptarea confirmării', (string) $pending)->description('Rezervări „în așteptare”')->icon('heroicon-o-clock')->color($pending ? 'warning' : 'success'),
        ];
        if ($user?->can('finance.view')) {
            $paidToday = (int) Payment::query()->where('status', 'succeeded')->where('succeeded_at', '>=', LocalTime::fromLocal($today, '00:00'))->sum('amount_minor');
            $stats[] = Stat::make('Încasat azi', Money::format($paidToday))->description('Plăți înregistrate (online, card, numerar, transfer)')->icon('heroicon-o-banknotes');
        }

        return $stats;
    }
}
