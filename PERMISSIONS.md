# Roluri și permisiuni

Sursă: caietul de sarcini, cap. 5 (Roluri inițiale, ACC-01, ACC-02), SEC-03, RAP-01, MKT-01.

## Principii
1. **Refuz implicit.** O acțiune este permisă numai dacă un rol al utilizatorului conține explicit permisiunea.
2. **Domeniu pe locații.** Permisiunile operaționale se aplică numai în locațiile atribuite utilizatorului (`location_user`). Un utilizator are acces la toate locațiile numai dacă are flagul `all_locations`.
3. **Relația cu pacientul.** Pentru datele clinice există două niveluri:
   - `*.view` – pacienții cu activitate (programare, vizită) în locațiile atribuite;
   - `*.view_own` – numai pacienții pe care specialistul i-a avut în programare sau i-a tratat.
4. **Verificare pe server.** Fiecare rută, componentă Livewire, API, descărcare de fișier și export trece prin Policy/Gate. Ascunderea butoanelor este doar ergonomie.
5. **Jurnalizare.** Se jurnalizează autentificările, accesările de dosare medicale, exporturile, schimbările de drepturi și impersonările (ACC-02, SEC-03).
6. **Superadministratorul** are configurația globală, utilizatorii, actualizările și rapoartele pe toate locațiile. **Accesul clinic rămâne explicit**: superadministratorul nu primește automat `medical_records.*`, ci și-l poate acorda, iar acordarea este jurnalizată.
7. Accesul la indicatori agregați **nu** dă acces la documentele clinice (RAP-01).

## Catalogul permisiunilor

Identificatorii sunt stabili (LNG-03). Etichetele lor sunt traductibile.

| Modul | Permisiuni |
|---|---|
| Pacienți | `patients.view`, `patients.create`, `patients.update`, `patients.archive`, `patients.export`, `patients.merge`, `patients.view_sensitive` (CNP) |
| Programări | `appointments.view`, `appointments.create`, `appointments.update`, `appointments.cancel`, `appointments.change_status`, `appointments.export` |
| Fișe medicale | `medical_records.view`, `medical_records.view_own`, `medical_records.create`, `medical_records.finalize`, `medical_records.amend`, `medical_records.export` |
| Prescripții | `prescriptions.view`, `prescriptions.create`, `prescriptions.amend` |
| Documente private | `documents.view`, `documents.upload`, `documents.delete` |
| Leaduri și sarcini | `leads.view`, `leads.manage`, `leads.export`, `tasks.manage` |
| Comenzi ochelari | `orders.view`, `orders.manage`, `orders.change_status`, `orders.export` |
| Financiar | `payments.view`, `payments.create`, `payments.refund`, `payments.export` |
| Comunicări | `communications.view_log`, `communications.send`, `communications.templates` |
| Campanii | `campaigns.view`, `campaigns.manage`, `campaigns.send` |
| Rapoarte | `reports.view`, `reports.financial`, `reports.export` |
| Orare | `schedules.view`, `schedules.manage` |
| Nomenclatoare | `catalog.manage` (locații, specialiști, servicii, resurse) |
| CMS și SEO | `cms.edit`, `cms.publish`, `media.manage`, `seo.manage` |
| Traduceri | `translations.edit`, `translations.publish` |
| Utilizatori | `users.view`, `users.manage`, `roles.manage` |
| Sistem | `settings.manage`, `integrations.manage`, `system.updates`, `system.backups`, `system.diagnostics`, `audit.view` |

## Roluri inițiale (personalizabile)

Legendă: ● acordat · L = limitat la locațiile atribuite · O = numai pacienții proprii · – refuzat

| Permisiune | Superadmin | Admin operațional | Manager locație | Recepție | Medic / Optometrist | Asistent | Marketing | Financiar | Editor site |
|---|---|---|---|---|---|---|---|---|---|
| patients.view / create / update | ● | ● | L | L | L | L | – | L (view) | – |
| patients.view_sensitive (CNP) | ● | – | L | L | L | – | – | – | – |
| patients.merge | ● | ● | L | – | – | – | – | – | – |
| patients.export | ● | ● | L | – | – | – | – | – | – |
| appointments.view / create / update / cancel | ● | ● | L | L | L | L | – | – | – |
| appointments.change_status | ● | ● | L | L | L | L | – | – | – |
| appointments.export | ● | ● | L | – | – | – | – | – | – |
| medical_records.view | – (explicit) | – | – | – | – | – | – | – | – |
| medical_records.view_own | – (explicit) | – | – | – | O | – | – | – | – |
| medical_records.create / finalize / amend | – | – | – | – | O | – | – | – | – |
| prescriptions.view | – | – | – | – | O | L (delegat) | – | – | – |
| prescriptions.create / amend | – | – | – | – | O | – | – | – | – |
| documents.view / upload | – | – | L | L (administrative) | O | L | – | – | – |
| leads.view / manage | ● | ● | L | L | – | – | ● | – | – |
| tasks.manage | ● | ● | L | L | L | L | ● | – | – |
| orders.view / manage / change_status | ● | ● | L | L | L | L | – | L (view) | – |
| payments.view / create | ● | ● | L | L | – | – | – | L | – |
| payments.refund | ● | – | L | – | – | – | – | L | – |
| payments.export | ● | – | L | – | – | – | – | L | – |
| communications.view_log / send | ● | ● | L | L | – | – | – | – | – |
| communications.templates | ● | ● | – | – | – | – | ● | – | – |
| campaigns.* | ● | – | – | – | – | – | ● | – | – |
| reports.view | ● | ● | L | – | – | – | ● (marketing) | L | – |
| reports.financial | ● | – | L | – | – | – | – | L | – |
| reports.export | ● | ● | L | – | – | – | ● | L | – |
| schedules.view | ● | ● | L | L | L (propriu) | L | – | – | – |
| schedules.manage | ● | ● | L | – | – | – | – | – | – |
| catalog.manage | ● | ● | – | – | – | – | – | – | – |
| cms.edit / media.manage / seo.manage | ● | – | – | – | – | – | ● | – | ● |
| cms.publish | ● | – | – | – | – | – | – | – | ● |
| translations.edit / publish | ● | – | – | – | – | – | – | – | ● |
| users.view / manage, roles.manage | ● | – | – | – | – | – | – | – | – |
| settings.manage, integrations.manage | ● | – | – | – | – | – | – | – | – |
| system.updates / backups / diagnostics | ● | – | – | – | – | – | – | – | – |
| audit.view | ● | – | – | – | – | – | – | – | – |

Note:
- Rolul **Marketing** nu are acces la pacienți ca fișe; vede numai contactele eligibile pentru campanii (după limbă, locație și acord), fără diagnostice (MKT-01).
- Rolul **Financiar** vede valori și plăți, fără conținut clinic (rol „Financiar” din caiet).
- **Editor site** nu are acces la pacienți.
- Matricea de mai sus este configurația inițială din seeder (`RolesAndPermissionsSeeder`). Beneficiarul o poate modifica din „Utilizatori → Roluri”, iar fiecare modificare este jurnalizată.

## Implementare
- Tabele: `roles`, `permissions`, `permission_role`, `role_user`, `location_user`, cu flagul `users.all_locations`.
- Serviciu: `App\Support\Access\AccessControl`, cu metodele `can(user, permission, ?location)` și `locationIdsFor(user, permission)`. Rezultatele sunt memorate pe durata cererii.
- Gate global: `Gate::before` rezolvă permisiunile `modul.acțiune`. Policies pe modele (`AppointmentPolicy`, `PatientPolicy` etc.) adaugă verificarea de locație și de relație cu pacientul.
- Interogări: scope-ul `visibleTo($user, $permission)` filtrează listele, ca utilizatorul să nu poată afla nici măcar existența înregistrărilor din afara domeniului său (T04).
