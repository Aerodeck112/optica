<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Cms\Services\SafeMarkdown;

/**
 * Șabloane cu variabile de forma {{ booking.code }}. Doar variabilele din lista permisă sunt acceptate;
 * valorile sunt escapate, iar corpul e-mailului este Markdown fără HTML brut.
 */
class TemplateRenderer
{
    public const VARIABLES = [
        'customer.first_name' => 'Prenumele clientului',
        'customer.name' => 'Numele complet',
        'booking.code' => 'Codul rezervării',
        'booking.package' => 'Experiența rezervată',
        'booking.date' => 'Data (ora locală)',
        'booking.time' => 'Ora de început (ora locală)',
        'booking.participants' => 'Număr participanți',
        'booking.total' => 'Total de plată',
        'booking.deposit' => 'Avans',
        'booking.status' => 'Starea rezervării',
        'booking.expires_at' => 'Termen de plată/confirmare',
        'booking.manage_url' => 'Link gestionare rezervare',
        'booking.previous_date' => 'Data anterioară (la reprogramare)',
        'lead.reference' => 'Număr cerere',
        'lead.type' => 'Tip cerere',
        'lead.date' => 'Data dorită',
        'payment.amount' => 'Suma încasată',
        'venue.address' => 'Adresa locației',
        'venue.maps_url' => 'Link hartă',
        'contact.phone' => 'Telefon',
        'contact.email' => 'E-mail',
        'review_url' => 'Link recenzie',
        'login_url' => 'Link autentificare cont',
        'unsubscribe_url' => 'Link dezabonare (doar marketing)',
        'site.url' => 'Adresa site-ului',
        'staff.admin_url' => 'Link în panou (doar mesaje interne)',
    ];

    public function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '';
        }, $template);
    }

    /** @return array<int, string> variabilele necunoscute dintr-un șablon (folosit la validarea în panou) */
    public function unknownVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z_.]+)\s*\}\}/', $template, $matches);

        return array_values(array_diff(array_unique($matches[1]), array_keys(self::VARIABLES)));
    }

    public function emailHtml(string $markdownBody, string $subject, array $vars, string $category): string
    {
        // Valorile variabilelor se escapează pentru Markdown (fără injectare de linkuri/format)
        $safeVars = array_map(fn ($v) => is_string($v) && ! str_starts_with($v, 'http') ? str_replace(['[', ']', '<', '>', '*', '_', '`'], ['\[', '\]', '&lt;', '&gt;', '\*', '\_', '\`'], $v) : $v, $vars);
        $html = SafeMarkdown::render($this->render($markdownBody, $safeVars));

        return view('emails.layout', [
            'subject' => $subject,
            'body' => $html,
            'unsubscribeUrl' => $category === 'marketing' ? ($vars['unsubscribe_url'] ?? null) : null,
            'contact' => ['phone' => $vars['contact.phone'] ?? null, 'email' => $vars['contact.email'] ?? null, 'address' => $vars['venue.address'] ?? null],
        ])->render();
    }

    public function plainText(string $markdownBody, array $vars): string
    {
        $text = $this->render($markdownBody, $vars);
        // linkurile Markdown devin „text: url”
        $text = preg_replace('/\[([^\]]+)\]\((https?:[^)]+)\)/', '$1: $2', $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
