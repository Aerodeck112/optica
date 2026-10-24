<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\Lead;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Facades\URL;

/**
 * Construiește valorile variabilelor permise în șabloane, din obiectele de context.
 */
class VariableBuilder
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function build(array $context): array
    {
        $vars = [
            'site.url' => url('/'),
            'venue.address' => trim(implode(', ', array_filter([$this->settings->filled('venue.address_street'), $this->settings->filled('venue.address_city') ?? 'Cluj-Napoca']))),
            'venue.maps_url' => $this->settings->filled('venue.maps_url') ?? '',
            'contact.phone' => $this->settings->filled('contact.phone_bookings') ?? $this->settings->filled('contact.phone_main') ?? '',
            'contact.email' => $this->settings->filled('contact.email_bookings') ?? $this->settings->filled('contact.email_general') ?? '',
            'review_url' => $this->settings->bool('booking.review_request_enabled') ? ($this->settings->filled('venue.google_review_url') ?? '') : '',
        ];

        if (! empty($context['__sample'])) {
            // valori de exemplu pentru mesajele de test din panou (marcate clar ca exemplu)
            return $vars + [
                'customer.first_name' => 'Ana', 'customer.name' => 'Ana Exemplu', 'booking.code' => 'AWEXEMPLU',
                'booking.package' => 'Sesiune VR (exemplu)', 'booking.date' => LocalTime::format(now()->addDays(3), 'd.m.Y'),
                'booking.time' => '18:00', 'booking.participants' => '4', 'booking.total' => '[sumă exemplu]', 'booking.deposit' => '[avans exemplu]',
                'booking.status' => 'Confirmată', 'booking.expires_at' => '', 'booking.manage_url' => url('/'), 'booking.previous_date' => '',
                'lead.reference' => 'CR-EXEMPLU', 'lead.type' => 'Aniversare', 'lead.date' => LocalTime::format(now()->addDays(10), 'd.m.Y'),
                'payment.amount' => '[sumă exemplu]', 'login_url' => url('/'), 'unsubscribe_url' => url('/'), 'staff.admin_url' => url('/admin'),
            ];
        }

        $customer = $context['customer'] ?? null;
        if ($customer instanceof Customer) {
            $vars['customer.first_name'] = $customer->first_name ?: '';
            $vars['customer.name'] = $customer->fullName();
            if (($context['category'] ?? null) === 'marketing') {
                $vars['unsubscribe_url'] = URL::signedRoute('unsubscribe.show', ['customer' => $customer->id, 'channel' => $context['channel'] ?? 'email']);
            }
        }

        $booking = $context['booking'] ?? null;
        if ($booking instanceof Booking) {
            $locale = $booking->locale ?: 'ro';
            $vars += [
                'booking.code' => $booking->code,
                'booking.package' => $booking->package?->getTranslation('name', $locale) ?? '',
                'booking.date' => $booking->starts_at ? LocalTime::format($booking->starts_at, 'l, j F Y') : '',
                'booking.time' => $booking->starts_at ? LocalTime::format($booking->starts_at, 'H:i') : '',
                'booking.participants' => (string) $booking->participants,
                'booking.total' => $booking->total_minor !== null ? Money::format($booking->total_minor) : 'de confirmat',
                'booking.deposit' => $booking->deposit_minor ? Money::format($booking->deposit_minor) : '',
                'booking.status' => $booking->statusLabel(),
                'booking.expires_at' => $booking->expires_at ? LocalTime::format($booking->expires_at, 'j F, H:i') : '',
                'booking.manage_url' => $context['manage_url'] ?? ($booking->manageUrl() ?? ''),
                'booking.previous_date' => isset($context['previous_start']) && $context['previous_start'] ? LocalTime::format($context['previous_start'], 'l, j F Y, H:i') : '',
                'staff.admin_url' => url('/admin/bookings/'.$booking->id),
            ];
        }

        $lead = $context['lead'] ?? null;
        if ($lead instanceof Lead) {
            $vars += [
                'lead.reference' => $lead->reference,
                'lead.type' => Lead::TYPES[$lead->type] ?? $lead->type,
                'lead.date' => $lead->preferred_date?->translatedFormat('l, j F Y') ?? '',
                'staff.admin_url' => url('/admin/leads/'.$lead->id),
            ];
        }

        if (isset($context['payment_amount'])) {
            $vars['payment.amount'] = Money::format((int) $context['payment_amount']);
        }
        foreach (['login_url', 'manage_url'] as $key) {
            if (isset($context[$key])) {
                $vars[$key === 'manage_url' ? 'booking.manage_url' : $key] = $context[$key];
            }
        }

        return $vars;
    }
}
