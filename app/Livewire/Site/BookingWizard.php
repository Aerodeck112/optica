<?php

namespace App\Livewire\Site;

use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\BookingException;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Service;
use App\Models\Specialist;
use App\Support\Consent\ConsentRecorder;
use App\Support\Ui\SiteUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Public booking: location → service → specialist (or first available) → date and time →
 * contact details → confirmation (cap. 4 "Flux public"). No account is needed and marketing
 * consent is optional and unchecked. The slot is held while the form is completed (PRG-03).
 */
class BookingWizard extends Component
{
    public const DAYS_SHOWN = 14;

    public int $step = 1;

    #[Url(as: 'locatie')]
    public ?string $locationCode = null;

    public bool $anyLocation = false;

    public ?int $serviceId = null;

    /** "first" or a specialist id */
    public string $specialistChoice = 'first';

    public string $date = '';

    public int $weekOffset = 0;

    #[Locked]
    public ?int $holdId = null;

    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $patientLocale = 'ro';

    public string $message = '';

    public bool $privacy = false;

    public array $marketing = ['email' => false, 'sms' => false, 'whatsapp' => false];

    /** Honeypot: real people never fill it. */
    public string $website = '';

    #[Locked]
    public ?string $manageUrl = null;

    #[Locked]
    public ?string $finalStatus = null;

    public function mount(): void
    {
        $this->token = bin2hex(random_bytes(16));
        $this->patientLocale = app()->getLocale();
        if ($this->locationCode && $this->location()) {
            $this->step = 2;
        }
    }

    // ----- steps ------------------------------------------------------------------------------

    public function chooseLocation(?string $code): void
    {
        $this->anyLocation = $code === null;
        $this->locationCode = $code;
        $this->serviceId = null;
        $this->releaseHold();
        $this->step = 2;
    }

    public function chooseService(int $id): void
    {
        abort_unless($this->services()->contains('id', $id), 404);
        $this->serviceId = $id;
        $this->specialistChoice = 'first';
        $this->releaseHold();
        $this->step = 3;
    }

    public function chooseSpecialist(string $choice): void
    {
        abort_unless($choice === 'first' || $this->specialists()->contains('id', (int) $choice), 404);
        $this->specialistChoice = $choice;
        $this->releaseHold();
        $this->weekOffset = 0;
        $this->date = '';
        $this->step = 4;
    }

    public function chooseDate(string $date): void
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422);
        $this->date = $date;
    }

    public function shiftDays(int $direction): void
    {
        $this->weekOffset = max(0, min(12, $this->weekOffset + ($direction > 0 ? 1 : -1)));
        $this->date = '';
    }

    public function back(): void
    {
        $this->releaseHold();
        $this->step = max(1, $this->step - 1);
    }

    public function chooseSlot(int $offeringId, string $startsAtUtc, BookingService $booking): void
    {
        if (RateLimiter::tooManyAttempts('booking-hold:'.request()->ip(), 30)) {
            $this->addError('slot', __('site.booking.too_many'));

            return;
        }
        RateLimiter::hit('booking-hold:'.request()->ip(), 600);

        $offering = $this->offerings()->firstWhere('id', $offeringId) ?? abort(404);
        $this->releaseHold();

        try {
            $hold = $booking->book($offering, [
                'starts_at' => CarbonImmutable::parse($startsAtUtc, 'UTC'),
                'source' => AppointmentSource::Online,
                'status' => AppointmentStatus::Held,
                'locale' => app()->getLocale(),
                'idempotency_key' => hash('sha256', $this->token.'|'.$offeringId.'|'.$startsAtUtc),
            ]);
        } catch (BookingException $e) {
            $this->addError('slot', $e->getMessage());

            return;
        }

        $this->holdId = $hold->id;
        $this->step = 5;
    }

    public function submit(BookingService $booking, ConsentRecorder $consents): void
    {
        $key = 'booking-submit:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            $this->addError('form', __('site.booking.too_many'));

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9 +().-]{9,}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'patientLocale' => ['required', 'in:'.implode(',', config('optica.locales'))],
            'message' => ['nullable', 'string', 'max:500'],
            'privacy' => ['accepted'],
            'marketing.*' => ['boolean'],
        ]);
        RateLimiter::hit($key, 600);

        // Bots fill hidden fields: pretend success without booking anything.
        if ($this->website !== '') {
            $this->releaseHold();
            $this->step = 6;
            $this->finalStatus = 'pending';

            return;
        }

        $hold = $this->holdId ? Appointment::find($this->holdId) : null;
        if (! $hold || $hold->status !== AppointmentStatus::Held) {
            $this->addError('form', __('booking.errors.hold_expired'));
            $this->step = 4;

            return;
        }

        $status = $hold->location->confirmation_mode === 'automatic' ? AppointmentStatus::Confirmed : AppointmentStatus::Pending;
        $token = bin2hex(random_bytes(24));

        try {
            $appointment = $booking->confirmHold($hold, [
                'contact_name' => trim($this->name),
                'contact_phone' => trim($this->phone),
                'contact_email' => $this->email ? mb_strtolower(trim($this->email)) : null,
                'locale' => $this->patientLocale,
                'admin_notes' => $this->message ? __('site.booking.patient_message_prefix', [], 'ro').' '.trim($this->message) : null,
                'manage_token_hash' => hash('sha256', $token),
            ], $status);
        } catch (BookingException $e) {
            $this->addError('form', $e->getMessage());
            $this->step = 4;
            $this->holdId = null;

            return;
        }

        $consents->recordBooking(
            ['phone' => $this->phone, 'email' => $this->email],
            $this->patientLocale, true, $this->marketing, $appointment->id,
        );

        $this->finalStatus = $appointment->status->value;
        $this->manageUrl = SiteUrl::route('booking.manage', ['token' => $token], $this->patientLocale);
        $this->holdId = $appointment->id;
        $this->step = 6;
    }

    private function releaseHold(): void
    {
        if ($this->holdId && $this->step < 6) {
            $hold = Appointment::find($this->holdId);
            if ($hold) {
                app(BookingService::class)->releaseHold($hold);
            }
            $this->holdId = null;
        }
    }

    // ----- data -------------------------------------------------------------------------------

    public function location(): ?Location
    {
        return $this->locationCode
            ? Location::where('code', $this->locationCode)->where('is_active', true)->where('is_public', true)->where('online_booking_enabled', true)->first()
            : null;
    }

    private function locations(): Collection
    {
        $ids = app(AvailabilityService::class)->onlineOfferings()->pluck('location_id')->unique();

        return Location::whereIn('id', $ids)->orderBy('sort_order')->get();
    }

    private function services(): Collection
    {
        $ids = app(AvailabilityService::class)->onlineOfferings(null, $this->location()?->id)->pluck('service_id')->unique();

        return Service::whereIn('id', $ids)->whereNotNull('published_at')->orderBy('sort_order')->get();
    }

    private function offerings(): Collection
    {
        $specialistId = $this->specialistChoice === 'first' ? null : (int) $this->specialistChoice;

        return app(AvailabilityService::class)->onlineOfferings($this->serviceId, $this->location()?->id, $specialistId);
    }

    private function specialists(): Collection
    {
        $ids = app(AvailabilityService::class)->onlineOfferings($this->serviceId, $this->location()?->id)->pluck('specialist_id')->unique();

        return Specialist::whereIn('id', $ids)->orderBy('sort_order')->orderBy('display_name')->get();
    }

    /** Paused specialists still appear, with their message instead of times (Evolvo „mesaj de pauză”). */
    private function pausedSpecialists(): Collection
    {
        if (! $this->serviceId) {
            return collect();
        }

        return LocationSpecialist::query()
            ->where('online_paused', true)->where('is_active', true)
            ->when($this->location(), fn ($q) => $q->where('location_id', $this->location()->id))
            ->whereIn('specialist_id', Offering::where('service_id', $this->serviceId)->where('is_active', true)->pluck('specialist_id'))
            ->with('specialist')->get()
            ->filter(fn ($l) => $l->specialist && $l->specialist->is_public);
    }

    /** @return array<string, list<array{offering_id: int, starts_at: string, local: string, specialist: string, location: string}>> by local date */
    private function slotsByDate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = config('optica.clinic_timezone');
        $availability = app(AvailabilityService::class);
        $seen = [];
        $byDate = [];
        foreach ($this->offerings() as $offering) {
            foreach ($availability->slots($offering, $from, $to, true) as $slot) {
                $local = $slot['starts_at']->setTimezone($tz);
                $key = $local->format('Y-m-d H:i').($this->anyLocation ? '|'.$offering->location_id : '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $byDate[$local->toDateString()][] = [
                    'offering_id' => $offering->id,
                    'starts_at' => $slot['starts_at']->utc()->toIso8601String(),
                    'local' => $local->format('H:i'),
                    'specialist' => $offering->specialist->display_name,
                    'location' => $offering->location->publicValue('name') ?? $offering->location->name(),
                ];
            }
        }
        foreach ($byDate as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['local'], $b['local']));
        }

        return $byDate;
    }

    public function render()
    {
        $tz = config('optica.clinic_timezone');
        $from = CarbonImmutable::now($tz)->startOfDay()->addDays($this->weekOffset * self::DAYS_SHOWN);
        $to = $from->addDays(self::DAYS_SHOWN - 1);
        $slots = $this->step === 4 ? $this->slotsByDate($from, $to) : [];
        if ($this->step === 4 && $this->date === '' && $slots) {
            $this->date = array_key_first($slots);
        }

        $hold = $this->holdId ? Appointment::with(['service', 'specialist', 'location'])->find($this->holdId) : null;

        return view('livewire.site.booking-wizard', [
            'locations' => $this->step === 1 ? $this->locations() : collect(),
            'selectedLocation' => $this->location(),
            'services' => $this->step === 2 ? $this->services() : collect(),
            'service' => $this->serviceId ? Service::find($this->serviceId) : null,
            'specialists' => $this->step === 3 ? $this->specialists() : collect(),
            'paused' => $this->step === 3 ? $this->pausedSpecialists() : collect(),
            'timeSlots' => $slots,
            'days' => collect(range(0, self::DAYS_SHOWN - 1))->map(fn ($i) => $from->addDays($i)),
            'hold' => $hold,
            'holdMinutes' => (int) config('optica.booking.hold_minutes'),
        ])->layout('components.layouts.site', [
            'title' => __('site.booking.title'),
            'description' => __('site.booking.meta_description'),
        ]);
    }
}
