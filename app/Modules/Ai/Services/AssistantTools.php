<?php

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiConversation;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\PricingService;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Lead;
use App\Modules\Notifications\Services\Notifier;
use App\Support\ContactNormalizer;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Tool-urile asistentului. Fiecare tool are o schemă strictă și este validat din nou pe server:
 * asistentul nu poate rula SQL, nu vede datele altor clienți, nu schimbă prețuri și nu emite documente.
 * Rezervările și cererile doar se PREGĂTESC; se creează numai după ce utilizatorul apasă „Confirm” pe rezumat.
 *
 * Schemele nu folosesc valori null (SDK-ul le elimină la retrimiterea istoricului): „necunoscut” = "" sau 0.
 */
class AssistantTools
{
    /** Efecte pentru interfață produse în tura curentă (rezumat de confirmat, predare către echipă). */
    public array $ui = ['summary' => null, 'handoff' => false];

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly BookingService $bookings,
        private readonly SettingsRepository $settings,
        private readonly Notifier $notifier,
    ) {}

    /** @return array<int, array<string, mixed>> definițiile pentru API (chei camelCase, ca în SDK) */
    public static function definitions(): array
    {
        $string = fn (string $description) => ['type' => 'string', 'description' => $description];
        $int = fn (string $description) => ['type' => 'integer', 'description' => $description];
        $tool = fn (string $name, string $description, array $properties) => [
            'name' => $name,
            'description' => $description,
            'strict' => true,
            'inputSchema' => ['type' => 'object', 'properties' => $properties ?: new \stdClass, 'required' => array_keys($properties), 'additionalProperties' => false],
        ];

        return [
            $tool('list_games', 'Lista jocurilor VR disponibile la arena din Cluj, cu datele confirmate (gen, jucători, vârstă minimă, durata jocului). Câmpurile lipsă nu sunt confirmate — nu le ghici.', [
                'audience' => ['type' => 'string', 'enum' => ['any', 'kids', 'teens_adults'], 'description' => 'Filtru public: any = toate.'],
            ]),
            $tool('list_packages', 'Pachetele (sesiune de joc, aniversare, eveniment) cu prețul, durata și capacitatea — doar valorile configurate. Arată și dacă pachetul se rezervă online sau prin cerere.', [
                'type' => ['type' => 'string', 'enum' => ['any', 'session', 'birthday', 'event', 'private'], 'description' => 'Filtru tip pachet.'],
            ]),
            $tool('check_availability', 'Orele de început libere pentru un pachet, la o dată, pentru un număr de participanți. Singura sursă de adevăr pentru disponibilitate.', [
                'package_id' => $int('ID-ul pachetului din list_packages.'),
                'date' => $string('Data în format AAAA-LL-ZZ (ora României).'),
                'participants' => $int('Număr de participanți.'),
            ]),
            $tool('get_quote', 'Prețul calculat de sistem pentru un pachet (cu TVA). Folosește-l în loc să calculezi singur.', [
                'package_id' => $int('ID-ul pachetului.'),
                'date' => $string('Data AAAA-LL-ZZ sau "" dacă nu e aleasă.'),
                'time' => $string('Ora HH:MM sau "" dacă nu e aleasă.'),
                'participants' => $int('Număr de participanți.'),
            ]),
            $tool('prepare_booking', 'Pregătește o rezervare și afișează utilizatorului un rezumat cu butonul „Confirm rezervarea”. NU creează rezervarea. Apelează-l doar după ce ai toate datele, inclusiv numele și e-mailul sau telefonul adultului care rezervă.', [
                'package_id' => $int('ID-ul pachetului.'),
                'date' => $string('Data AAAA-LL-ZZ.'),
                'time' => $string('Ora de început HH:MM, una dintre orele libere din check_availability.'),
                'participants' => $int('Număr total de participanți.'),
                'contact_name' => $string('Numele adultului care rezervă.'),
                'contact_email' => $string('E-mailul adultului sau "" dacă a dat doar telefonul.'),
                'contact_phone' => $string('Telefonul adultului sau "" dacă a dat doar e-mailul.'),
                'children_under_12' => $int('Câți participanți au sub 12 ani (0 dacă niciunul). Nu cere nume sau date despre copii.'),
                'teens_12_17' => $int('Câți participanți au 12–17 ani.'),
                'adults' => $int('Câți participanți sunt adulți.'),
                'notes' => $string('Mențiuni utile pentru echipă sau "". Fără date medicale.'),
            ]),
            $tool('prepare_event_request', 'Pregătește o cerere de ofertă pentru aniversare, team building, eveniment privat sau grup mare și afișează un rezumat de confirmat. NU trimite cererea fără confirmarea utilizatorului.', [
                'type' => ['type' => 'string', 'enum' => array_keys(Lead::TYPES)],
                'contact_name' => $string('Numele persoanei de contact.'),
                'contact_email' => $string('E-mail sau "".'),
                'contact_phone' => $string('Telefon sau "".'),
                'company_name' => $string('Compania sau "".'),
                'preferred_date' => $string('Data dorită AAAA-LL-ZZ sau "".'),
                'participants' => $int('Număr estimat de participanți sau 0 dacă nu se știe.'),
                'message' => $string('Detaliile cererii, pe scurt.'),
            ]),
            $tool('find_my_booking', 'Verifică o rezervare existentă a utilizatorului: necesită codul rezervării ȘI e-mailul sau telefonul folosit la rezervare. Fără potrivire exactă nu se afișează nimic.', [
                'booking_code' => $string('Codul rezervării (ex. AWK7M3QX9).'),
                'email_or_phone' => $string('E-mailul sau telefonul folosit la rezervare.'),
            ]),
            $tool('send_manage_link', 'Trimite pe e-mailul rezervării linkul securizat de gestionare (anulare/reprogramare). Doar după find_my_booking reușit în această conversație.', [
                'booking_code' => $string('Codul rezervării verificate.'),
            ]),
            $tool('handoff_to_team', 'Predă discuția echipei: afișează utilizatorului linkul de contact. Folosește-l pentru reclamații, plăți, rambursări, cereri speciale sau când nu știi răspunsul.', [
                'reason' => $string('Motivul, pe scurt.'),
            ]),
        ];
    }

    /** @return array<string, mixed> rezultatul trimis modelului */
    public function execute(string $name, array $input, AiConversation $conversation): array
    {
        try {
            return match ($name) {
                'list_games' => $this->listGames((string) ($input['audience'] ?? 'any')),
                'list_packages' => $this->listPackages((string) ($input['type'] ?? 'any')),
                'check_availability' => $this->checkAvailability($input),
                'get_quote' => $this->getQuote($input),
                'prepare_booking' => $this->prepareBooking($input, $conversation),
                'prepare_event_request' => $this->prepareEventRequest($input, $conversation),
                'find_my_booking' => $this->findMyBooking($input, $conversation),
                'send_manage_link' => $this->sendManageLink($input, $conversation),
                'handoff_to_team' => $this->handoff(),
                default => ['error' => 'Tool necunoscut.'],
            };
        } catch (BookingException $e) {
            return ['error' => $e->getMessage(), 'reason' => $e->reason];
        }
    }

    private function listGames(string $audience): array
    {
        $games = Game::query()->visibleOnSite()->publishedIn('ro')->with('genre')
            ->when($audience !== 'any', fn ($q) => $q->whereIn('audience', [$audience, 'all']))
            ->get();

        return ['games' => $games->map(fn (Game $g) => array_filter([
            'title' => $g->getTranslation('title', 'ro'),
            'status' => $g->cluj_status === 'coming_soon' ? 'în curând' : 'disponibil',
            'genre' => $g->genre?->getTranslation('name', 'ro'),
            'players' => $g->playersLabel(),
            'min_age' => $g->min_age,
            'play_minutes' => $g->play_minutes,
            'tagline' => $g->getTranslation('tagline', 'ro', false) ?: null,
            'url' => $g->url('ro'),
        ], fn ($v) => $v !== null && $v !== ''))->values()->all(), 'note' => 'Valorile lipsă nu sunt confirmate pentru Cluj.'];
    }

    private function listPackages(string $type): array
    {
        $packages = Package::query()->active()->publishedIn('ro')->when($type !== 'any', fn ($q) => $q->where('type', $type))->get();

        return ['packages' => $packages->map(fn (Package $p) => array_filter([
            'id' => $p->id,
            'name' => $p->getTranslation('name', 'ro'),
            'type' => Package::TYPES[$p->type] ?? $p->type,
            'summary' => $p->getTranslation('summary', 'ro', false) ?: null,
            'booking' => $p->supportsInstantBooking() && $this->settings->bool('booking.online_enabled') ? 'rezervare online' : 'cerere de disponibilitate (confirmă echipa)',
            'session' => $p->session_mode === 'shared' ? 'sesiune comună' : 'sesiune privată',
            'price' => $p->price_minor !== null && $p->pricing_model !== 'quote' ? Money::format($p->price_minor).($p->pricing_model === 'per_person' ? ' / persoană' : ' / rezervare') : 'la cerere',
            'booking_minutes' => $p->slot_minutes,
            'play_minutes' => $p->play_minutes,
            'participants' => $p->max_participants ? ($p->min_participants ?: 1).'–'.$p->max_participants : null,
            'min_age' => $p->min_age,
        ], fn ($v) => $v !== null && $v !== ''))->values()->all()];
    }

    private function package(int $id): Package
    {
        $package = Package::query()->active()->publishedIn('ro')->find($id);
        if (! $package) {
            throw new BookingException('package', 'Pachetul nu există sau nu este disponibil.');
        }

        return $package;
    }

    private function date(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! CarbonImmutable::createFromFormat('!Y-m-d', $date)) {
            throw new BookingException('date', 'Data trebuie să fie în formatul AAAA-LL-ZZ.');
        }
        if ($date < LocalTime::now()->toDateString()) {
            throw new BookingException('date', 'Data este în trecut.');
        }

        return $date;
    }

    private function checkAvailability(array $input): array
    {
        $package = $this->package((int) $input['package_id']);
        $date = $this->date((string) $input['date']);
        $result = $this->availability->slotsFor($package, $date, max(1, (int) $input['participants']));

        return match ($result['status']) {
            'ok' => ['date' => $date, 'available_times' => array_values(array_map(fn ($s) => $s['local_time'], array_filter($result['slots'], fn ($s) => $s['available']))), 'note' => $result['message']],
            'closed' => ['date' => $date, 'available_times' => [], 'note' => 'Arena este închisă în această zi.'],
            'out_of_range' => ['date' => $date, 'available_times' => [], 'note' => 'Data este în afara perioadei în care se poate rezerva online.'],
            default => ['date' => $date, 'available_times' => [], 'note' => 'Pentru acest pachet disponibilitatea se confirmă de echipă: pregătește o cerere (prepare_booking trimite o cerere, nu o rezervare instant).'],
        };
    }

    private function start(string $date, string $time): CarbonImmutable
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time) || ! ($start = LocalTime::fromLocal($date, $time))) {
            throw new BookingException('time', 'Ora trebuie să fie în formatul HH:MM și să existe în acea zi.');
        }

        return $start;
    }

    private function getQuote(array $input): array
    {
        $package = $this->package((int) $input['package_id']);
        $start = ($input['date'] ?? '') !== '' && ($input['time'] ?? '') !== '' ? $this->start($this->date($input['date']), $input['time']) : null;
        $quote = $this->pricing->quote($package, $start, max(1, (int) $input['participants']), []);

        return $quote->totalMinor === null
            ? ['price' => 'la cerere', 'note' => 'Prețul nu este configurat online; echipa trimite oferta.']
            : ['total' => Money::format($quote->totalMinor), 'deposit' => $quote->depositMinor ? Money::format($quote->depositMinor) : 'fără avans', 'vat' => 'TVA inclus', 'lines' => array_map(fn ($i) => $i['description'].' × '.$i['quantity'].' = '.Money::format($i['total_minor']), $quote->items)];
    }

    private function contact(array $input): array
    {
        $email = ContactNormalizer::email($input['contact_email'] ?? '');
        $phone = ContactNormalizer::phone($input['contact_phone'] ?? '');
        $name = trim((string) ($input['contact_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BookingException('contact', 'Am nevoie de numele adultului care face rezervarea.');
        }
        if (! $email && ! $phone) {
            throw new BookingException('contact', 'Am nevoie de un e-mail sau un telefon valid.');
        }

        return [$name, $email ? trim((string) $input['contact_email']) : null, $phone ? trim((string) $input['contact_phone']) : null];
    }

    private function prepareBooking(array $input, AiConversation $conversation): array
    {
        if (! $this->settings->bool('ai.allow_booking_creation')) {
            return $this->handoff('Pregătirea rezervărilor prin asistent este dezactivată.');
        }
        $package = $this->package((int) $input['package_id']);
        $participants = (int) $input['participants'];
        $ages = ['under_12' => max(0, (int) $input['children_under_12']), '12_17' => max(0, (int) $input['teens_12_17']), 'adults' => max(0, (int) $input['adults'])];
        if (array_sum($ages) !== $participants) {
            throw new BookingException('participants', 'Suma pe grupe de vârstă trebuie să fie egală cu numărul de participanți.');
        }
        if ($ages['adults'] < 1) {
            throw new BookingException('participants', 'La fiecare rezervare participă sau răspunde cel puțin un adult.');
        }
        [$name, $email, $phone] = $this->contact($input);
        $date = $this->date((string) $input['date']);
        $start = $this->start($date, (string) $input['time']);
        $quote = $this->pricing->quote($package, $start, $participants, []);

        $state = (array) $conversation->state;
        $this->releaseHold($state);
        $instant = $package->supportsInstantBooking() && $this->settings->bool('booking.online_enabled');
        $hold = null;
        if ($instant) {
            $hold = $this->bookings->hold($package, $start, $participants, [], 'ai'); // aruncă excepție dacă ora nu mai e liberă
        }

        $id = Str::lower(Str::random(16));
        $policies = $this->bookings->requiredPolicyTypes($ages);
        $summary = [
            'kind' => 'booking',
            'expires_at' => now()->addMinutes((int) config('aw.ai.summary_ttl_minutes', 15))->toIso8601String(),
            'data' => [
                'package_id' => $package->id, 'starts_at' => $start->toIso8601String(), 'participants' => $participants, 'age_groups' => array_filter($ages),
                'contact_name' => $name, 'contact_email' => $email, 'contact_phone' => $phone, 'notes' => mb_substr((string) $input['notes'], 0, 1000),
                'instant' => $instant, 'policies' => $policies,
                'hold_public_id' => $hold ? $hold['hold']->public_id : null, 'hold_token' => $hold['token'] ?? null,
            ],
        ];
        $state['summaries'] = [$id => $summary];
        $conversation->state = $state;

        $rows = [
            ['Experiență', $package->getTranslation('name', 'ro')],
            ['Data și ora', LocalTime::format($start, 'd.m.Y, H:i')],
            ['Participanți', $participants.' ('.collect(['under_12' => 'sub 12 ani', '12_17' => '12–17 ani', 'adults' => 'adulți'])->filter(fn ($l, $k) => $ages[$k] > 0)->map(fn ($l, $k) => $ages[$k].' '.$l)->implode(', ').')'],
            ['Contact', trim($name.' · '.implode(' · ', array_filter([$email, $phone])))],
            ['Total', $quote->totalMinor !== null ? Money::format($quote->totalMinor).' (TVA inclus)' : 'se stabilește de echipă'],
        ];
        if ($quote->depositMinor) {
            $rows[] = ['Avans', Money::format($quote->depositMinor)];
        }
        $links = collect($policies)->map(fn ($type) => LegalDocument::current($type))->filter()->map(fn ($d) => $d->getTranslation('title', 'ro').': '.lroute('legal.show', ['slug' => $d->getTranslation('slug', 'ro')], 'ro'))->implode("\n");
        $this->ui['summary'] = [
            'id' => $id,
            'title' => $instant ? 'Verifică rezervarea' : 'Verifică cererea de rezervare',
            'rows' => $rows,
            'note' => ($instant ? 'Ora este reținută pentru câteva minute. ' : 'Echipa confirmă disponibilitatea și revine pe e-mail/telefon. ')
                ."Prin „Confirm rezervarea” accepți:\n".$links,
        ];

        return ['status' => 'summary_shown', 'summary_id' => $id, 'mode' => $instant ? 'rezervare (după confirmarea utilizatorului)' : 'cerere de rezervare (confirmă echipa)', 'instruction' => 'Spune-i utilizatorului să verifice rezumatul și să apese „Confirm rezervarea” dacă totul e corect. Nu spune că rezervarea este făcută.'];
    }

    private function prepareEventRequest(array $input, AiConversation $conversation): array
    {
        [$name, $email, $phone] = $this->contact($input);
        $date = (string) ($input['preferred_date'] ?? '');
        if ($date !== '') {
            $date = $this->date($date);
        }
        $id = Str::lower(Str::random(16));
        $state = (array) $conversation->state;
        $state['summaries'] = [$id => [
            'kind' => 'lead',
            'expires_at' => now()->addMinutes((int) config('aw.ai.summary_ttl_minutes', 15))->toIso8601String(),
            'data' => [
                'type' => $input['type'], 'contact_name' => $name, 'contact_email' => $email, 'contact_phone' => $phone,
                'company_name' => mb_substr((string) $input['company_name'], 0, 190) ?: null, 'preferred_date' => $date ?: null,
                'participants' => (int) $input['participants'] ?: null, 'message' => mb_substr((string) $input['message'], 0, 2000),
            ],
        ]];
        $conversation->state = $state;
        $this->ui['summary'] = [
            'id' => $id,
            'title' => 'Verifică cererea de ofertă',
            'rows' => array_values(array_filter([
                ['Tip', Lead::TYPES[$input['type']] ?? $input['type']],
                ['Contact', trim($name.' · '.implode(' · ', array_filter([$email, $phone])))],
                $input['company_name'] ? ['Companie', $input['company_name']] : null,
                $date ? ['Data dorită', CarbonImmutable::parse($date)->format('d.m.Y')] : null,
                (int) $input['participants'] ? ['Participanți (estimat)', (string) (int) $input['participants']] : null,
                ['Detalii', mb_substr((string) $input['message'], 0, 300)],
            ])),
            'note' => 'Cererea nu rezervă încă arena. Echipa revine cu o ofertă. Datele sunt folosite doar pentru această cerere (vezi Politica de confidențialitate).',
        ];

        return ['status' => 'summary_shown', 'summary_id' => $id, 'instruction' => 'Cere utilizatorului să verifice rezumatul și să apese „Confirm”.'];
    }

    private function findMyBooking(array $input, AiConversation $conversation): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $input['booking_code']));
        $contact = (string) $input['email_or_phone'];
        $email = ContactNormalizer::email($contact);
        $phone = ContactNormalizer::phone($contact);
        $booking = Booking::query()->where('code', $code)->first();
        $matches = $booking && (
            ($email && ContactNormalizer::email($booking->contact_email) === $email)
            || ($phone && ContactNormalizer::phone($booking->contact_phone) === $phone)
        );
        $state = (array) $conversation->state;
        $state['lookup_failures'] = (int) ($state['lookup_failures'] ?? 0) + ($matches ? 0 : 1);
        $conversation->state = $state;
        if ($state['lookup_failures'] > 3) {
            return $this->handoff('Prea multe încercări de verificare a unei rezervări.');
        }
        if (! $matches) {
            return ['found' => false, 'note' => 'Nu am găsit o rezervare cu aceste date. Nu oferi alte detalii.'];
        }
        $conversation->forceFill(['verified_booking_id' => $booking->id, 'verified_until' => now()->addMinutes(15)]);

        return ['found' => true, 'code' => $booking->code, 'status' => $booking->statusLabel(), 'when' => $booking->localStart(), 'participants' => $booking->participants,
            'package' => $booking->package?->getTranslation('name', 'ro'), 'payment' => $booking->paymentStatusLabel(),
            'note' => 'Pentru anulare sau reprogramare, oferă trimiterea linkului de gestionare pe e-mail (send_manage_link).'];
    }

    private function sendManageLink(array $input, AiConversation $conversation): array
    {
        $booking = $conversation->verified_booking_id && $conversation->verified_until?->isFuture() ? Booking::query()->find($conversation->verified_booking_id) : null;
        if (! $booking || $booking->code !== strtoupper((string) $input['booking_code'])) {
            return ['sent' => false, 'note' => 'Rezervarea trebuie verificată mai întâi cu find_my_booking.'];
        }
        if (! $booking->contact_email || ! $booking->customer) {
            return $this->handoff('Rezervarea nu are e-mail; linkul nu poate fi trimis automat.');
        }
        $token = $booking->rotateManageToken();
        $this->notifier->sendToCustomer($booking->customer, 'booking.manage_link', 'email', ['booking' => $booking, 'manage_url' => lroute('booking.manage', ['token' => $token], $booking->locale ?: 'ro')], 'service', "booking:{$booking->id}:manage-link:ai:".now()->format('YmdHi'), $booking);

        return ['sent' => true, 'to' => ContactNormalizer::maskEmail($booking->contact_email)];
    }

    private function handoff(string $reason = ''): array
    {
        $this->ui['handoff'] = true;

        return ['handoff' => true, 'note' => 'Utilizatorul vede acum linkul de contact cu echipa.'.($reason ? ' Motiv: '.$reason : '')];
    }

    public function releaseHold(array $state): void
    {
        foreach ((array) ($state['summaries'] ?? []) as $summary) {
            if (! empty($summary['data']['hold_public_id']) && ! empty($summary['data']['hold_token'])) {
                $this->bookings->releaseHold($summary['data']['hold_public_id'], $summary['data']['hold_token']);
            }
        }
    }
}
