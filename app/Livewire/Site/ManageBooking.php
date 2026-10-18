<?php

namespace App\Livewire\Site;

use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\BookingException;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The patient's private link (PRG-05): unguessable (random 192-bit token, only its hash is stored),
 * limited to this one appointment, and usable only until the appointment starts.
 */
class ManageBooking extends Component
{
    #[Locked]
    public string $token = '';

    public bool $confirmingCancel = false;

    public string $date = '';

    public ?string $notice = null;

    public function mount(string $token): void
    {
        $key = 'booking-manage:'.request()->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 30), 429);
        RateLimiter::hit($key, 600);
        $this->token = $token;
        abort_unless($this->appointment(), 404);
    }

    private function appointment(): ?Appointment
    {
        if (strlen($this->token) !== 48 || ! ctype_xdigit($this->token)) {
            return null;
        }

        return Appointment::with(['service', 'specialist', 'location', 'offering'])
            ->where('manage_token_hash', hash('sha256', $this->token))
            ->first();
    }

    private function editable(Appointment $a): bool
    {
        return in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)
            && CarbonImmutable::instance($a->starts_at)->isFuture();
    }

    public function cancel(BookingService $booking): void
    {
        $a = $this->appointment() ?? abort(404);
        abort_unless($this->editable($a), 403);
        $booking->cancel($a, 'patient_request', note: 'online');
        $this->confirmingCancel = false;
        $this->notice = __('site.manage.cancelled');
    }

    public function reschedule(string $startsAtUtc, BookingService $booking): void
    {
        $a = $this->appointment() ?? abort(404);
        abort_unless($this->editable($a) && $a->offering, 403);
        try {
            $booking->reschedule($a, CarbonImmutable::parse($startsAtUtc, 'UTC'), $a->offering);
        } catch (BookingException $e) {
            $this->addError('slot', $e->getMessage());

            return;
        }
        $this->notice = __('site.manage.rescheduled');
        $this->date = '';
    }

    public function render(AvailabilityService $availability)
    {
        $tz = config('optica.clinic_timezone');
        $a = $this->appointment() ?? abort(404);
        $slots = [];
        if ($this->editable($a) && $a->offering) {
            $from = CarbonImmutable::now($tz)->startOfDay();
            foreach ($availability->slots($a->offering, $from, $from->addDays(20), true) as $slot) {
                $local = $slot['starts_at']->setTimezone($tz);
                $slots[$local->toDateString()][] = ['local' => $local->format('H:i'), 'utc' => $slot['starts_at']->utc()->toIso8601String()];
            }
            if ($this->date === '' && $slots) {
                $this->date = array_key_first($slots);
            }
        }

        return view('livewire.site.manage-booking', [
            'a' => $a,
            'editable' => $this->editable($a),
            'timeSlots' => $slots,
        ])->layout('components.layouts.site', ['title' => __('site.manage.title'), 'noindex' => true]);
    }
}
