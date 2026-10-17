<?php

namespace App\Http\Controllers\Crm;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Support\Access\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AccessControl $access): View
    {
        $user = $request->user();
        $appointments = collect();

        if ($user->can('appointments.view')) {
            $tz = config('optica.clinic_timezone');
            $start = CarbonImmutable::now($tz)->startOfDay()->utc();
            $locationIds = $access->locationIdsFor($user, 'appointments.view');

            $appointments = Appointment::query()
                ->with(['location', 'specialist', 'service'])
                ->whereBetween('starts_at', [$start, $start->addDay()])
                ->whereNotIn('status', [AppointmentStatus::Held->value, AppointmentStatus::Expired->value])
                ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds))
                ->orderBy('starts_at')
                ->limit(200)
                ->get();
        }

        return view('crm.dashboard', ['appointments' => $appointments]);
    }
}
