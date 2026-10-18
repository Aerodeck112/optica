<?php

namespace App\Livewire\Site;

use App\Models\Lead;
use App\Models\Location;
use App\Support\Consent\ConsentRecorder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Site forms create leads in the CRM (WEB-05, CRM-01). The thank-you message is shown only
 * after the lead is saved; double submissions do not create duplicates.
 */
class LeadForm extends Component
{
    #[Locked]
    public string $type = 'contact';

    #[Locked]
    public string $token = '';

    #[Locked]
    public string $source = '';

    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $company = '';
    public string $message = '';
    public ?int $locationId = null;
    public bool $privacy = false;
    public bool $marketingEmail = false;
    public string $website = '';
    public bool $sent = false;

    public function mount(string $type = 'contact'): void
    {
        $this->type = in_array($type, Lead::TYPES, true) ? $type : 'contact';
        $this->token = bin2hex(random_bytes(16));
        $this->source = mb_substr('/'.request()->path(), 0, 255);
    }

    public function submit(ConsentRecorder $consents): void
    {
        $key = 'lead-form:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('form', __('site.booking.too_many'));

            return;
        }
        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]{9,}$/'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:190'],
            'company' => [$this->type === 'b2b' ? 'required' : 'nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'locationId' => ['nullable', 'integer', 'exists:locations,id'],
            'privacy' => ['accepted'],
        ]);
        RateLimiter::hit($key, 600);

        if ($this->website === '') {
            $lead = Lead::firstOrCreate(['idempotency_key' => hash('sha256', $this->token)], [
                'type' => $this->type,
                'name' => trim($this->name),
                'phone' => $this->phone ?: null,
                'email' => $this->email ? mb_strtolower(trim($this->email)) : null,
                'company' => $this->company ?: null,
                'message' => trim($this->message),
                'locale' => app()->getLocale(),
                'source' => $this->source,
                'utm' => array_filter(request()->only(['utm_source', 'utm_medium', 'utm_campaign'])) ?: null,
                'location_id' => $this->locationId,
            ]);
            if ($lead->wasRecentlyCreated) {
                $consents->recordBooking(['phone' => $this->phone, 'email' => $this->email], app()->getLocale(), true,
                    $this->email ? ['email' => $this->marketingEmail] : [], null, 'contact_form', $lead->id);
            }
        }

        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.site.lead-form', [
            'locations' => $this->type === 'contact'
                ? Location::where('is_active', true)->where('is_public', true)->whereNotNull('published_at')->orderBy('sort_order')->get()
                : collect(),
        ]);
    }
}
