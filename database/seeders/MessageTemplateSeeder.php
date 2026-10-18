<?php

namespace Database\Seeders;

use App\Models\MessageRule;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Starting texts for operational messages (email and SMS, RO/HU). They contain no medical detail
 * and can be edited in Comunicare → Șabloane. Rules start disabled: nothing is sent until the
 * clinic configures a channel and switches a rule on. Existing edits are never overwritten.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $t = [
            'booking_received' => [
                'ro' => ['Am primit cererea de programare', "Bună ziua, {patient_name}!\n\nAm primit cererea dumneavoastră de programare pentru {date}, ora {time}, la {location}. Vă contactăm pentru confirmare.\n\nPentru modificări: {manage_link}", 'Optica Optofarm: am primit cererea de programare pentru {date}, ora {time}, la {location}. Va contactam pentru confirmare.'],
                'hu' => ['Megkaptuk az időpontkérését', "Jó napot, {patient_name}!\n\nMegkaptuk időpontkérését: {date}, {time}, {location}. A megerősítés miatt felvesszük Önnel a kapcsolatot.\n\nMódosítás: {manage_link}", 'Optica Optofarm: megkaptuk idopontkereset ({date}, {time}, {location}). A megerositeshez felvesszuk Onnel a kapcsolatot.'],
            ],
            'appointment_confirmed' => [
                'ro' => ['Programarea este confirmată', "Bună ziua, {patient_name}!\n\nProgramarea dumneavoastră este confirmată: {date}, ora {time}, la {location} ({location_address}).\n{instructions}\n\nPentru anulare sau mutare: {manage_link}\nTelefon: {location_phone}", 'Optica Optofarm: programare confirmata {date}, ora {time}, {location}. Modificari: {manage_link}'],
                'hu' => ['Időpontja megerősítve', "Jó napot, {patient_name}!\n\nIdőpontját megerősítettük: {date}, {time}, {location} ({location_address}).\n{instructions}\n\nLemondás vagy áthelyezés: {manage_link}\nTelefon: {location_phone}", 'Optica Optofarm: idopontja megerositve: {date}, {time}, {location}. Modositas: {manage_link}'],
            ],
            'appointment_rescheduled' => [
                'ro' => ['Programarea a fost mutată', "Bună ziua, {patient_name}!\n\nProgramarea dumneavoastră a fost mutată: {date}, ora {time}, la {location}.\n\nPentru modificări: {manage_link}", 'Optica Optofarm: programarea a fost mutata pe {date}, ora {time}, {location}. Modificari: {manage_link}'],
                'hu' => ['Időpontját áthelyeztük', "Jó napot, {patient_name}!\n\nIdőpontját áthelyeztük: {date}, {time}, {location}.\n\nMódosítás: {manage_link}", 'Optica Optofarm: idopontja uj ideje: {date}, {time}, {location}. Modositas: {manage_link}'],
            ],
            'appointment_cancelled' => [
                'ro' => ['Programarea a fost anulată', "Bună ziua, {patient_name}!\n\nProgramarea din {date}, ora {time}, la {location} a fost anulată.\nPentru o nouă programare: {booking_link} sau {location_phone}.", 'Optica Optofarm: programarea din {date}, ora {time} a fost anulata. Programare noua: {booking_link}'],
                'hu' => ['Időpontját lemondtuk', "Jó napot, {patient_name}!\n\nA(z) {date}, {time} időpontját ({location}) lemondtuk.\nÚj időpont: {booking_link} vagy {location_phone}.", 'Optica Optofarm: a(z) {date}, {time} idopontot lemondtuk. Uj idopont: {booking_link}'],
            ],
            'appointment_reminder' => [
                'ro' => ['Vă reamintim programarea', "Bună ziua, {patient_name}!\n\nVă reamintim programarea de {date}, ora {time}, la {location} ({location_address}).\n{instructions}\n\nDacă nu puteți ajunge: {manage_link}", 'Optica Optofarm: va asteptam {date}, ora {time}, la {location}. Daca nu puteti ajunge: {manage_link}'],
                'hu' => ['Emlékeztető az időpontjáról', "Jó napot, {patient_name}!\n\nEmlékeztetjük időpontjára: {date}, {time}, {location} ({location_address}).\n{instructions}\n\nHa nem tud eljönni: {manage_link}", 'Optica Optofarm: varjuk {date}, {time}, {location}. Ha nem tud eljonni: {manage_link}'],
            ],
            'order_ready' => [
                'ro' => ['Comanda dumneavoastră este gata', "Bună ziua, {patient_name}!\n\nComanda {order_number} este gata și o puteți ridica de la {location} ({location_address}).\nTelefon: {location_phone}", 'Optica Optofarm: comanda {order_number} este gata de ridicare la {location}.'],
                'hu' => ['Rendelése elkészült', "Jó napot, {patient_name}!\n\nA(z) {order_number} számú rendelése elkészült, átveheti itt: {location} ({location_address}).\nTelefon: {location_phone}", 'Optica Optofarm: a(z) {order_number} rendelese atveheto: {location}.'],
            ],
            'recall' => [
                'ro' => ['Este timpul pentru controlul periodic', "Bună ziua, {patient_name}!\n\nVă invităm la controlul periodic la {location}. Programări: {booking_link} sau {location_phone}.", 'Optica Optofarm: va invitam la controlul periodic la {location}. Programari: {booking_link}'],
                'hu' => ['Ideje az időszakos kontrollnak', "Jó napot, {patient_name}!\n\nSzeretettel várjuk időszakos kontrollra: {location}. Időpontfoglalás: {booking_link} vagy {location_phone}.", 'Optica Optofarm: varjuk idoszakos kontrollra: {location}. Idopont: {booking_link}'],
            ],
        ];

        foreach ($t as $type => $locales) {
            foreach ($locales as $locale => [$subject, $email, $sms]) {
                MessageTemplate::firstOrCreate(['type' => $type, 'channel' => 'email', 'locale' => $locale, 'location_id' => null], ['subject' => $subject, 'body' => $email]);
                MessageTemplate::firstOrCreate(['type' => $type, 'channel' => 'sms', 'locale' => $locale, 'location_id' => null], ['body' => $sms, 'ascii_only' => true]);
            }
            MessageRule::firstOrCreate(['type' => $type, 'location_id' => null], [
                'enabled' => false,
                'channels' => ['sms', 'email'],
                'offset_hours' => $type === 'appointment_reminder' ? 24 : null,
            ]);
        }
    }
}
