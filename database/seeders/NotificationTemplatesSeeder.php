<?php

namespace Database\Seeders;

use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Șabloanele mesajelor de serviciu (română). Editabile din panou, cu variabile validate.
 */
class NotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $sign = "\n\nTe așteptăm,\nEchipa Another World Cluj";
        $details = "**Cod:** {{ booking.code }}\n**Experiența:** {{ booking.package }}\n**Data:** {{ booking.date }}, ora {{ booking.time }}\n**Participanți:** {{ booking.participants }}\n**Total:** {{ booking.total }}";

        $templates = [
            ['booking.request_received', 'email', 'Am primit cererea ta ({{ booking.code }})', "Bună, {{ customer.first_name }}!\n\nAm primit cererea pentru **{{ booking.package }}**. Echipa verifică disponibilitatea și revine cu un răspuns. Cererea nu ocupă încă arena.\n\n**Cod:** {{ booking.code }}\n**Data dorită:** {{ booking.date }}\n**Participanți:** {{ booking.participants }}\n\n[Vezi cererea]({{ booking.manage_url }}){$sign}"],
            ['booking.pending', 'email', 'Rezervarea {{ booking.code }} este înregistrată', "Bună, {{ customer.first_name }}!\n\nOra aleasă este rezervată pentru tine. Rezervarea devine confirmată după verificarea echipei sau după plata avansului, dacă este cazul.\n\n{$details}\n\n[Gestionează rezervarea]({{ booking.manage_url }}){$sign}"],
            ['booking.confirmed', 'email', 'Rezervare confirmată: {{ booking.date }}, ora {{ booking.time }}', "Bună, {{ customer.first_name }}!\n\nRezervarea ta este confirmată.\n\n{$details}\n\nAdresa: {{ venue.address }}\n\n[Gestionează rezervarea]({{ booking.manage_url }}){$sign}"],
            ['booking.confirmed', 'sms', null, 'Another World Cluj: rezervarea {{ booking.code }} este confirmată pentru {{ booking.date }}, ora {{ booking.time }}.'],
            ['booking.rescheduled', 'email', 'Rezervarea {{ booking.code }} a fost mutată', "Bună, {{ customer.first_name }}!\n\nRezervarea ta a fost mutată.\n\nÎnainte: {{ booking.previous_date }}\n**Acum: {{ booking.date }}, ora {{ booking.time }}**\n\n[Gestionează rezervarea]({{ booking.manage_url }}){$sign}"],
            ['booking.cancelled', 'email', 'Rezervarea {{ booking.code }} a fost anulată', "Bună, {{ customer.first_name }}!\n\nRezervarea {{ booking.code }} pentru {{ booking.date }} a fost anulată. Dacă ai întrebări despre o eventuală rambursare, răspunde la acest e-mail sau contactează-ne la {{ contact.phone }}.\n\nEchipa Another World Cluj"],
            ['booking.expired', 'email', 'Rezervarea {{ booking.code }} a expirat', "Bună, {{ customer.first_name }}!\n\nRezervarea {{ booking.code }} a expirat, deoarece nu a fost confirmată în termen. Ora a fost eliberată. Poți face oricând o rezervare nouă pe {{ site.url }}.\n\nEchipa Another World Cluj"],
            ['booking.reminder_long', 'email', 'Ne vedem mâine: {{ booking.time }}', "Bună, {{ customer.first_name }}!\n\nÎți reamintim de rezervarea ta.\n\n{$details}\n\nAdresa: {{ venue.address }}\nVino cu câteva minute mai devreme, ca să începeți la timp.\n\n[Gestionează rezervarea]({{ booking.manage_url }}){$sign}"],
            ['booking.reminder_long', 'sms', null, 'Another World Cluj: te așteptăm {{ booking.date }}, ora {{ booking.time }} (rezervarea {{ booking.code }}).'],
            ['booking.reminder_short', 'email', 'În curând: aventura ta începe la {{ booking.time }}', "Bună, {{ customer.first_name }}!\n\nAventura ta începe azi, la ora **{{ booking.time }}**. Adresa: {{ venue.address }}\n\n[Vezi pe hartă]({{ venue.maps_url }}){$sign}"],
            ['booking.reminder_short', 'sms', null, 'Another World Cluj: aventura ta începe azi la {{ booking.time }}. Te așteptăm!'],
            ['booking.post_visit', 'email', 'Mulțumim că ai fost în altă lume', "Bună, {{ customer.first_name }}!\n\nMulțumim că ai ales Another World Cluj. Sperăm că v-ați distrat!\n\nDacă vrei să ne spui cum a fost, răspunde la acest e-mail. {{ review_url }}\n\nEchipa Another World Cluj"],
            ['booking.payment_received', 'email', 'Am primit plata pentru {{ booking.code }}', "Bună, {{ customer.first_name }}!\n\nAm primit plata de **{{ payment.amount }}** pentru rezervarea {{ booking.code }}.\n\n[Gestionează rezervarea]({{ booking.manage_url }}){$sign}"],
            ['booking.manage_link', 'email', 'Linkul pentru rezervarea {{ booking.code }}', "Bună, {{ customer.first_name }}!\n\nAici este linkul nou pentru gestionarea rezervării {{ booking.code }}. Linkurile trimise anterior nu mai sunt valabile.\n\n[Gestionează rezervarea]({{ booking.manage_url }}){$sign}"],
            ['lead.received', 'email', 'Am primit cererea ta ({{ lead.reference }})', "Bună, {{ customer.first_name }}!\n\nAm primit cererea pentru **{{ lead.type }}**, cu data dorită {{ lead.date }}. Revenim cu un răspuns cât mai curând.\n\nNumăr de referință: {{ lead.reference }}\n\nEchipa Another World Cluj"],
            ['account.login_link', 'email', 'Linkul de autentificare în contul tău', "Bună, {{ customer.first_name }}!\n\nFolosește linkul de mai jos pentru a intra în cont. Este valabil 30 de minute și poate fi folosit o singură dată.\n\n[Intră în cont]({{ login_url }})\n\nDacă nu ai cerut acest link, poți ignora mesajul.\n\nEchipa Another World Cluj"],
            ['staff.new_booking', 'email', 'Rezervare nouă: {{ booking.code }} ({{ booking.status }})', "Rezervare nouă, sursa site/asistent.\n\n{$details}\n**Stare:** {{ booking.status }}\n\n[Deschide în panou]({{ staff.admin_url }})"],
            ['staff.new_lead', 'email', 'Cerere nouă: {{ lead.type }} ({{ lead.reference }})', "Cerere nouă: **{{ lead.type }}**, data dorită {{ lead.date }}.\n\n[Deschide în panou]({{ staff.admin_url }})"],
        ];

        foreach ($templates as [$key, $channel, $subject, $body]) {
            NotificationTemplate::query()->updateOrCreate(
                ['key' => $key, 'channel' => $channel, 'locale' => 'ro'],
                ['subject' => $subject, 'body' => $body, 'category' => 'service', 'is_active' => true],
            );
        }
    }
}
