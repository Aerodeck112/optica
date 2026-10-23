<?php

namespace App\Modules\Cms\Seo;

use App\Modules\Booking\Services\ScheduleService;
use App\Modules\Core\Settings\SettingsRepository;

/**
 * Date structurate schema.org construite DOAR din informații configurate.
 * Fără adresă, program, rating sau recenzii presupuse. Prezența markup-ului nu garantează rich results.
 */
class StructuredData
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ScheduleService $schedule,
    ) {}

    public function business(): array
    {
        $s = fn (string $key) => $this->settings->filled($key);
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'EntertainmentBusiness',
            '@id' => url('/').'#business',
            'name' => __('site.brand'),
            'url' => url('/'),
            'logo' => asset('media/brand/aw-logo-stacked.svg'),
            'image' => asset($this->settings->get('site.default_og_image', '/media/brand/og-default.jpg')),
            'description' => __('site.footer.tagline'),
            'brand' => ['@type' => 'Brand', 'name' => 'Another World'],
        ];

        if ($s('venue.address_street')) {
            $data['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $s('venue.address_street'),
                'addressLocality' => $s('venue.address_city') ?? 'Cluj-Napoca',
                'postalCode' => $s('venue.address_postal_code'),
                'addressRegion' => 'Cluj',
                'addressCountry' => 'RO',
            ]);
        } else {
            // localitatea este un fapt confirmat; strada nu se inventează
            $data['areaServed'] = ['@type' => 'City', 'name' => 'Cluj-Napoca'];
        }

        if ($s('venue.lat') && $s('venue.lng')) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $s('venue.lat'), 'longitude' => (float) $s('venue.lng')];
        }
        if ($s('venue.maps_url')) {
            $data['hasMap'] = $s('venue.maps_url');
        }
        if ($phone = $s('contact.phone_main')) {
            $data['telephone'] = $phone;
        }
        if ($email = $s('contact.email_general')) {
            $data['email'] = $email;
        }

        $sameAs = array_values(array_filter([
            $s('social.facebook'), $s('social.instagram'), $s('social.tiktok'), $s('social.youtube'), $s('venue.tripadvisor_url'),
        ]));
        if ($sameAs) {
            $data['sameAs'] = $sameAs;
        }

        if ($this->schedule->isConfigured()) {
            $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
            $data['openingHoursSpecification'] = collect($this->schedule->weeklyForDisplay())
                ->reject(fn ($d) => $d['closed'])
                ->map(fn ($d) => [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/'.$days[$d['weekday']],
                    'opens' => $d['opens'],
                    'closes' => $d['closes'],
                ])->values()->all();
        }

        return $data;
    }

    public function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
            'name' => __('site.brand'),
            'url' => url('/'),
            'inLanguage' => app()->getLocale(),
            'publisher' => ['@id' => url('/').'#business'],
        ];
    }

    /** @param array<int, array{question:string, answer:string}> $items */
    public function faq(array $items): ?array
    {
        if (! $items) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ], $items),
        ];
    }

    public function article(string $title, string $url, ?string $image, ?string $published, ?string $modified): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $title,
            'mainEntityOfPage' => $url,
            'image' => $image,
            'datePublished' => $published,
            'dateModified' => $modified,
            'publisher' => ['@id' => url('/').'#business'],
        ]);
    }
}
