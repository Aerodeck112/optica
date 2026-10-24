<?php

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Models\AiKnowledgeEntry;
use App\Modules\Booking\Services\ScheduleService;
use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Core\Settings\SettingsRepository;

/**
 * Cunoștințele pe care asistentul are voie să le folosească: doar informații aprobate în panou
 * (articole din baza de cunoștințe, întrebări frecvente marcate pentru AI, date completate în Setări).
 * Ce nu este aici sau în rezultatul unui tool, asistentul nu știe și nu presupune.
 */
class KnowledgeBase
{
    public function __construct(private readonly SettingsRepository $settings, private readonly ScheduleService $schedule) {}

    public function render(string $locale = 'ro'): string
    {
        $sections = [];

        $facts = array_filter([
            'Nume' => 'Another World Cluj — arenă de realitate virtuală (VR) multiplayer, parte a rețelei Another World',
            'Oraș' => 'Cluj-Napoca',
            'Adresă' => $this->settings->filled('venue.address_street'),
            'Indicații de acces' => $this->settings->filled('venue.access_notes'),
            'Telefon' => $this->settings->filled('contact.phone_bookings') ?? $this->settings->filled('contact.phone_main'),
            'E-mail' => $this->settings->filled('contact.email_bookings') ?? $this->settings->filled('contact.email_general'),
            'Hartă' => $this->settings->filled('venue.maps_url'),
            'Site' => rtrim((string) config('app.url'), '/'),
        ]);
        $sections[] = "## Locația\n".collect($facts)->map(fn ($v, $k) => "- {$k}: {$v}")->implode("\n")
            ."\n- Experiențe: doar realitate virtuală (VR). Nu există LED floor / pixel floor sau alte atracții.";

        $hours = $this->schedule->weeklyForDisplay();
        if ($this->schedule->isConfigured()) {
            $sections[] = "## Program obișnuit (ora României)\n".collect($hours)->map(fn ($row) => '- '.$row['label'].': '.($row['closed'] ? 'închis' : $row['opens'].'–'.$row['closes']))->implode("\n")
                ."\nPentru o dată anume (sărbători, program special) folosește întotdeauna check_availability.";
        } else {
            $sections[] = "## Program\nProgramul nu este încă publicat. Nu indica ore; recomandă formularul de contact.";
        }

        $cancel = $this->settings->filled('booking.free_cancellation_hours');
        $resched = $this->settings->filled('booking.free_reschedule_hours');
        $policy = [];
        if ($cancel !== null) {
            $policy[] = "Anulare gratuită cu cel puțin {$cancel} ore înainte, conform politicii de rezervare.";
        }
        if ($resched !== null) {
            $policy[] = "Reprogramare online cu cel puțin {$resched} ore înainte.";
        }
        $docs = LegalDocument::query()->where('status', 'published')->get()->map(fn ($d) => '- '.$d->getTranslation('title', 'ro').': '.lroute('legal.show', ['slug' => $d->getTranslation('slug', 'ro')], 'ro'))->implode("\n");
        $sections[] = "## Reguli și documente\n".($policy ? implode("\n", $policy)."\n" : "Condițiile de anulare nu sunt publicate în acest moment; trimite clientul la politica de rezervare sau la echipă.\n").$docs;

        $faqs = Faq::query()->where('is_published', true)->where('use_in_ai', true)->orderBy('category')->orderBy('sort')->get();
        if ($faqs->isNotEmpty()) {
            $sections[] = "## Întrebări frecvente aprobate\n".$faqs->map(fn (Faq $f) => '### '.$f->getTranslation('question', $locale, true)."\n".trim((string) $f->getTranslation('answer', $locale, true)))->implode("\n\n");
        }

        $entries = AiKnowledgeEntry::query()->where('status', 'approved')->orderBy('topic')->get();
        if ($entries->isNotEmpty()) {
            $sections[] = "## Informații aprobate de echipă\n".$entries->map(fn ($e) => '### '.$e->topic."\n".trim((string) $e->content))->implode("\n\n");
        }

        return implode("\n\n", $sections);
    }
}
