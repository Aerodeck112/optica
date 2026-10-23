<?php

namespace App\Modules\Core\Settings;

/**
 * Definiția tuturor setărilor administrabile. Nicio valoare comercială nu este presupusă:
 * câmpurile necunoscute rămân goale („De completat”) și nu se afișează pe site.
 */
final class SettingsSchema
{
    /** @return array<string, array{group:string,label:string,type:string,secret?:bool,launch?:bool,help?:string,default?:mixed,options?:array}> */
    public static function definitions(): array
    {
        return [
            // ——— Date firmă și locație ———
            'company.legal_name' => ['group' => 'company', 'label' => 'Denumire juridică', 'type' => 'string', 'launch' => true],
            'company.tax_id' => ['group' => 'company', 'label' => 'CUI / CIF', 'type' => 'string', 'launch' => true, 'help' => 'Cu prefix RO doar dacă firma este plătitoare de TVA.'],
            'company.reg_no' => ['group' => 'company', 'label' => 'Nr. Registrul Comerțului', 'type' => 'string', 'launch' => true],
            'company.registered_office' => ['group' => 'company', 'label' => 'Sediu social', 'type' => 'text', 'launch' => true],
            'company.share_capital' => ['group' => 'company', 'label' => 'Capital social', 'type' => 'string'],
            'company.vat_payer' => ['group' => 'company', 'label' => 'Plătitor de TVA', 'type' => 'select', 'options' => ['' => 'De completat', 'yes' => 'Da', 'no' => 'Nu'], 'launch' => true],
            'company.vat_on_collection' => ['group' => 'company', 'label' => 'TVA la încasare', 'type' => 'select', 'options' => ['' => 'De completat', 'yes' => 'Da', 'no' => 'Nu']],
            'company.iban' => ['group' => 'company', 'label' => 'IBAN', 'type' => 'string', 'launch' => true],
            'company.bank' => ['group' => 'company', 'label' => 'Banca', 'type' => 'string', 'launch' => true],
            'company.county_code' => ['group' => 'company', 'label' => 'Cod județ sediu (ISO 3166-2:RO, ex: RO-CJ)', 'type' => 'string', 'help' => 'Necesar în XML-ul e-Factura.'],

            'venue.address_street' => ['group' => 'venue', 'label' => 'Adresa locației (stradă, număr, etaj)', 'type' => 'string', 'launch' => true],
            'venue.address_city' => ['group' => 'venue', 'label' => 'Localitate', 'type' => 'string', 'default' => 'Cluj-Napoca'],
            'venue.address_postal_code' => ['group' => 'venue', 'label' => 'Cod poștal', 'type' => 'string'],
            'venue.access_notes' => ['group' => 'venue', 'label' => 'Indicații de acces / parcare', 'type' => 'text'],
            'venue.lat' => ['group' => 'venue', 'label' => 'Latitudine', 'type' => 'string', 'launch' => true],
            'venue.lng' => ['group' => 'venue', 'label' => 'Longitudine', 'type' => 'string', 'launch' => true],
            'venue.maps_url' => ['group' => 'venue', 'label' => 'Link Google Maps', 'type' => 'url', 'launch' => true],
            'venue.tripadvisor_url' => ['group' => 'venue', 'label' => 'Link Tripadvisor', 'type' => 'url'],
            'venue.google_review_url' => ['group' => 'venue', 'label' => 'Link pentru recenzii Google', 'type' => 'url'],

            'contact.phone_main' => ['group' => 'contact', 'label' => 'Telefon principal', 'type' => 'phone', 'launch' => true],
            'contact.phone_bookings' => ['group' => 'contact', 'label' => 'Telefon rezervări', 'type' => 'phone'],
            'contact.whatsapp' => ['group' => 'contact', 'label' => 'WhatsApp (număr)', 'type' => 'phone'],
            'contact.email_general' => ['group' => 'contact', 'label' => 'E-mail general', 'type' => 'email', 'launch' => true],
            'contact.email_bookings' => ['group' => 'contact', 'label' => 'E-mail rezervări', 'type' => 'email', 'launch' => true],
            'contact.email_events' => ['group' => 'contact', 'label' => 'E-mail aniversări și evenimente', 'type' => 'email'],
            'contact.email_billing' => ['group' => 'contact', 'label' => 'E-mail facturare', 'type' => 'email'],
            'contact.email_privacy' => ['group' => 'contact', 'label' => 'E-mail protecția datelor', 'type' => 'email', 'launch' => true],
            'contact.notifications_staff' => ['group' => 'contact', 'label' => 'E-mail intern pentru alerte (cereri noi, erori)', 'type' => 'email'],

            'social.facebook' => ['group' => 'social', 'label' => 'Facebook', 'type' => 'url'],
            'social.instagram' => ['group' => 'social', 'label' => 'Instagram', 'type' => 'url'],
            'social.tiktok' => ['group' => 'social', 'label' => 'TikTok', 'type' => 'url'],
            'social.youtube' => ['group' => 'social', 'label' => 'YouTube', 'type' => 'url'],

            // ——— Rezervări ———
            'booking.online_enabled' => ['group' => 'booking', 'label' => 'Rezervare online activă', 'type' => 'bool', 'default' => true],
            'booking.slot_interval_minutes' => ['group' => 'booking', 'label' => 'Interval între ore de început (minute)', 'type' => 'int', 'default' => 30],
            'booking.min_lead_minutes' => ['group' => 'booking', 'label' => 'Termen minim înainte de rezervare (minute)', 'type' => 'int', 'default' => 120],
            'booking.max_advance_days' => ['group' => 'booking', 'label' => 'Rezervare cu cel mult (zile înainte)', 'type' => 'int', 'default' => 90],
            'booking.hold_minutes' => ['group' => 'booking', 'label' => 'Durata blocării temporare a slotului în timpul completării (minute)', 'type' => 'int', 'default' => 15],
            'booking.auto_confirm' => ['group' => 'booking', 'label' => 'Confirmă automat rezervările cu preț și capacitate configurate', 'type' => 'bool', 'default' => false, 'help' => 'Dezactivat: rezervările intră „în așteptare” până le confirmă recepția.'],
            'booking.pending_minutes_online' => ['group' => 'booking', 'label' => 'Termen plată online înainte de expirare (minute)', 'type' => 'int', 'default' => 30],
            'booking.pending_hours_transfer' => ['group' => 'booking', 'label' => 'Termen plată prin transfer înainte de expirare (ore)', 'type' => 'int', 'default' => 48],
            'booking.pending_hours_unconfirmed' => ['group' => 'booking', 'label' => 'Termen confirmare de către recepție (ore, 0 = fără expirare)', 'type' => 'int', 'default' => 0],
            'booking.free_cancellation_hours' => ['group' => 'booking', 'label' => 'Anulare gratuită cu cel puțin (ore înainte)', 'type' => 'int', 'launch' => true, 'help' => 'Se aplică doar după ce politica de rezervare este validată.'],
            'booking.free_reschedule_hours' => ['group' => 'booking', 'label' => 'Reprogramare online cu cel puțin (ore înainte)', 'type' => 'int', 'launch' => true],
            'booking.max_reschedules' => ['group' => 'booking', 'label' => 'Număr maxim de reprogramări online', 'type' => 'int', 'default' => 1],
            'booking.payment_methods' => ['group' => 'booking', 'label' => 'Metode de plată acceptate la rezervare', 'type' => 'multiselect', 'options' => ['at_location' => 'La locație', 'bank_transfer' => 'Transfer bancar', 'online' => 'Online (procesator)'], 'default' => ['at_location']],
            'booking.reminder_long_hours' => ['group' => 'booking', 'label' => 'Primul reminder (ore înainte)', 'type' => 'int', 'default' => 24],
            'booking.reminder_short_hours' => ['group' => 'booking', 'label' => 'Al doilea reminder (ore înainte, 0 = dezactivat)', 'type' => 'int', 'default' => 3],
            'booking.reminder_sms_enabled' => ['group' => 'booking', 'label' => 'Remindere și prin SMS', 'type' => 'bool', 'default' => false],
            'booking.post_visit_hours' => ['group' => 'booking', 'label' => 'Mesaj după vizită (ore după final, 0 = dezactivat)', 'type' => 'int', 'default' => 3],
            'booking.review_request_enabled' => ['group' => 'booking', 'label' => 'Include invitația de recenzie în mesajul de după vizită', 'type' => 'bool', 'default' => false],
            'booking.age_groups' => ['group' => 'booking', 'label' => 'Categorii de vârstă cerute la rezervare', 'type' => 'json', 'default' => ['under_12' => 'Sub 12 ani', '12_17' => '12–17 ani', 'adults' => '18+ ani']],

            // ——— Site ———
            'site.show_unconfirmed_games' => ['group' => 'site', 'label' => 'Afișează și jocurile din catalogul rețelei neconfirmate pentru Cluj (marcate clar)', 'type' => 'bool', 'default' => false],
            'site.default_og_image' => ['group' => 'site', 'label' => 'Imagine implicită pentru distribuire', 'type' => 'string', 'default' => '/media/brand/og-default.jpg'],
            'site.search_console_verification' => ['group' => 'site', 'label' => 'Cod verificare Google Search Console', 'type' => 'string'],
            'site.anti_spam_turnstile_site_key' => ['group' => 'site', 'label' => 'Cloudflare Turnstile — cheie site (opțional)', 'type' => 'string'],
            'site.anti_spam_turnstile_secret' => ['group' => 'site', 'label' => 'Cloudflare Turnstile — cheie secretă', 'type' => 'string', 'secret' => true],

            // ——— Analytics (încărcate doar după consimțământ) ———
            'analytics.gtm_id' => ['group' => 'analytics', 'label' => 'Google Tag Manager ID', 'type' => 'string'],
            'analytics.ga4_id' => ['group' => 'analytics', 'label' => 'GA4 Measurement ID', 'type' => 'string'],
            'analytics.ads_id' => ['group' => 'analytics', 'label' => 'Google Ads ID (AW-…)', 'type' => 'string'],
            'analytics.ads_conversion_label' => ['group' => 'analytics', 'label' => 'Google Ads — etichetă conversie rezervare confirmată', 'type' => 'string'],
            'analytics.meta_pixel_id' => ['group' => 'analytics', 'label' => 'Meta Pixel ID', 'type' => 'string'],

            // ——— Marketing ———
            'marketing.frequency_cap_count' => ['group' => 'marketing', 'label' => 'Maxim mesaje promoționale per persoană', 'type' => 'int', 'default' => 2],
            'marketing.frequency_cap_days' => ['group' => 'marketing', 'label' => '… în intervalul (zile)', 'type' => 'int', 'default' => 7],
            'marketing.sms_cost_per_segment_micro' => ['group' => 'marketing', 'label' => 'Cost estimat SMS per segment (micro-RON)', 'type' => 'int', 'help' => 'Ex: 0,045 lei = 45000. Folosit pentru plafonul de cost.'],
            'marketing.quiet_hours_start' => ['group' => 'marketing', 'label' => 'Nu trimite promoții după ora', 'type' => 'string', 'default' => '20:00'],
            'marketing.quiet_hours_end' => ['group' => 'marketing', 'label' => 'Nu trimite promoții înainte de ora', 'type' => 'string', 'default' => '09:00'],

            // ——— Fidelizare ———
            'loyalty.enabled' => ['group' => 'loyalty', 'label' => 'Program de fidelizare activ', 'type' => 'bool', 'default' => false],
            'loyalty.points_per_ron' => ['group' => 'loyalty', 'label' => 'Puncte per leu încasat', 'type' => 'int', 'default' => 1],
            'loyalty.points_expiry_months' => ['group' => 'loyalty', 'label' => 'Expirarea punctelor (luni, 0 = nu expiră)', 'type' => 'int', 'default' => 12],
            'loyalty.referral_points_referrer' => ['group' => 'loyalty', 'label' => 'Puncte pentru recomandare (cel care recomandă)', 'type' => 'int', 'default' => 0],
            'loyalty.referral_points_referred' => ['group' => 'loyalty', 'label' => 'Puncte bonus pentru persoana recomandată', 'type' => 'int', 'default' => 0],

            // ——— Asistent AI ———
            'ai.enabled' => ['group' => 'ai', 'label' => 'Asistent virtual activ pe site', 'type' => 'bool', 'default' => false],
            'ai.daily_budget_usd' => ['group' => 'ai', 'label' => 'Buget zilnic maxim (USD)', 'type' => 'string', 'default' => '5'],
            'ai.retention_days' => ['group' => 'ai', 'label' => 'Păstrarea conversațiilor (zile)', 'type' => 'int', 'default' => 30],
            'ai.allow_booking_creation' => ['group' => 'ai', 'label' => 'Asistentul poate pregăti rezervări (confirmarea o face utilizatorul)', 'type' => 'bool', 'default' => true],

            // ——— Facturare ———
            'finance.invoice_due_days' => ['group' => 'finance', 'label' => 'Termen de plată factură (zile)', 'type' => 'int', 'default' => 0],
            'finance.efactura_b2c' => ['group' => 'finance', 'label' => 'Transmite în e-Factura și facturile către persoane fizice (B2C)', 'type' => 'bool', 'default' => true, 'help' => 'Verificați obligația curentă cu contabilul.'],
            'finance.efactura_deadline_working_days' => ['group' => 'finance', 'label' => 'Termen transmitere e-Factura (zile lucrătoare)', 'type' => 'int', 'default' => 5],
            'finance.efactura_auto_upload' => ['group' => 'finance', 'label' => 'Trimite automat la ANAF după emitere', 'type' => 'bool', 'default' => false],
            'finance.invoice_footer' => ['group' => 'finance', 'label' => 'Mențiuni pe factură', 'type' => 'text'],

            // ——— Retenție (zile) ———
            'retention.message_logs_days' => ['group' => 'retention', 'label' => 'Jurnal mesaje (zile)', 'type' => 'int', 'default' => 365],
            'retention.lost_leads_days' => ['group' => 'retention', 'label' => 'Cereri pierdute (zile)', 'type' => 'int', 'default' => 365],
            'retention.inactive_customers_months' => ['group' => 'retention', 'label' => 'Anonimizare clienți fără activitate și fără obligații legale (luni)', 'type' => 'int', 'default' => 36],
            'retention.audit_days' => ['group' => 'retention', 'label' => 'Jurnal de audit (zile)', 'type' => 'int', 'default' => 730],
        ];
    }

    public static function groups(): array
    {
        return [
            'company' => 'Date firmă',
            'venue' => 'Locație',
            'contact' => 'Contact',
            'social' => 'Rețele sociale',
            'booking' => 'Rezervări',
            'site' => 'Site',
            'analytics' => 'Analytics',
            'marketing' => 'Marketing',
            'loyalty' => 'Fidelizare',
            'ai' => 'Asistent AI',
            'finance' => 'Facturare',
            'retention' => 'Retenție date',
        ];
    }

    public static function get(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }
}
