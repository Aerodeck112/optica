<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Crm\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Transformă regulile unui segment într-o interogare. Orice segment include implicit doar clienții
 * cu consimțământ activ pe canalul campaniei, neanonimizați și cu adresă pe acel canal.
 * Excluderile (dezabonări, bounce) se verifică din nou la fiecare trimitere.
 */
final class SegmentQuery
{
    public const RULES = [
        'type' => 'Tip client',
        'tags_any' => 'Are cel puțin una dintre etichete',
        'min_bookings' => 'Minimum rezervări',
        'min_visits' => 'Minimum vizite',
        'visited_within_days' => 'A venit în ultimele N zile',
        'not_visited_for_days' => 'Nu a mai venit de cel puțin N zile',
        'booked_package_type' => 'A rezervat tipul de pachet',
        'locale' => 'Limba preferată',
        'tier_ids' => 'Nivel de fidelitate',
    ];

    public static function build(array $rules, string $channel): Builder
    {
        $query = Customer::query()
            ->whereNull('anonymized_at')
            ->whereNull('merged_into_id')
            ->whereNotNull($channel === 'sms' ? 'phone_normalized' : 'email_normalized')
            ->whereHas('consents', fn ($q) => $q->where('channel', $channel)->where('purpose', 'marketing')->where('status', 'granted'));

        $now = CarbonImmutable::now();
        foreach ($rules as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            match ($key) {
                'type' => $query->where('type', $value),
                'tags_any' => $query->where(function ($q) use ($value) {
                    foreach ((array) $value as $tag) {
                        $q->orWhereJsonContains('tags', $tag);
                    }
                }),
                'min_bookings' => $query->where('bookings_count', '>=', (int) $value),
                'min_visits' => $query->where('visits_count', '>=', (int) $value),
                'visited_within_days' => $query->where('last_visit_at', '>=', $now->subDays((int) $value)),
                'not_visited_for_days' => $query->where(fn ($q) => $q->whereNull('last_visit_at')->orWhere('last_visit_at', '<', $now->subDays((int) $value))),
                'booked_package_type' => $query->whereHas('bookings', fn ($q) => $q->whereIn('status', ['confirmed', 'checked_in', 'completed'])->whereHas('package', fn ($p) => $p->where('type', $value))),
                'locale' => $query->where('locale', $value),
                'tier_ids' => $query->whereIn('loyalty_tier_id', (array) $value),
                default => null,
            };
        }

        return $query;
    }
}
