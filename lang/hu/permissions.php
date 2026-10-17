<?php

return [
    'modules' => [
        'patients' => 'Páciensek',
        'appointments' => 'Időpontok',
        'medical_records' => 'Orvosi kartonok',
        'prescriptions' => 'Receptek',
        'documents' => 'Privát dokumentumok',
        'leads' => 'Megkeresések (leadek)',
        'tasks' => 'Feladatok',
        'orders' => 'Szemüvegrendelések',
        'payments' => 'Befizetések',
        'communications' => 'Kommunikáció',
        'campaigns' => 'Kampányok',
        'reports' => 'Jelentések',
        'schedules' => 'Munkarendek',
        'catalog' => 'Telephelyek, szakemberek, szolgáltatások',
        'cms' => 'Weboldal-oldalak',
        'media' => 'Médiatár',
        'seo' => 'SEO',
        'translations' => 'Fordítások',
        'users' => 'Felhasználók',
        'roles' => 'Szerepkörök',
        'settings' => 'Beállítások',
        'integrations' => 'Integrációk',
        'system' => 'Rendszer',
        'audit' => 'Naplózás',
    ],
    'actions' => [
        'patients' => [
            'view' => 'Páciensek megtekintése',
            'create' => 'Páciensek felvétele',
            'update' => 'Páciensadatok módosítása',
            'archive' => 'Páciensek archiválása',
            'export' => 'Páciensek exportálása',
            'merge' => 'Duplikált adatlapok összevonása',
            'view_sensitive' => 'Személyi szám megtekintése',
        ],
        'appointments' => [
            'view' => 'Időpontok megtekintése',
            'create' => 'Időpontok létrehozása',
            'update' => 'Módosítás és áthelyezés',
            'cancel' => 'Időpontok lemondása',
            'change_status' => 'Állapot módosítása (megérkezett, lezárva…)',
            'export' => 'Időpontok exportálása',
        ],
        'medical_records' => [
            'view' => 'Kartonok megtekintése a hozzárendelt telephelyeken',
            'view_own' => 'Saját páciensek kartonjainak megtekintése',
            'create' => 'Kartonok kitöltése',
            'finalize' => 'Kartonok lezárása',
            'amend' => 'Lezárt kartonok javítása (kiegészítés)',
            'export' => 'Kartonok exportálása',
        ],
        'prescriptions' => [
            'view' => 'Receptek megtekintése',
            'create' => 'Receptek kiállítása',
            'amend' => 'Receptek javítása',
        ],
        'documents' => [
            'view' => 'Dokumentumok megtekintése és letöltése',
            'upload' => 'Dokumentumok feltöltése',
            'delete' => 'Dokumentumok törlése',
        ],
        'leads' => [
            'view' => 'Megkeresések megtekintése',
            'manage' => 'Megkeresések kezelése',
            'export' => 'Megkeresések exportálása',
        ],
        'tasks' => [
            'manage' => 'Feladatok kezelése',
        ],
        'orders' => [
            'view' => 'Rendelések megtekintése',
            'manage' => 'Rendelések létrehozása és módosítása',
            'change_status' => 'Rendelések állapotának módosítása',
            'export' => 'Rendelések exportálása',
        ],
        'payments' => [
            'view' => 'Befizetések megtekintése',
            'create' => 'Befizetések rögzítése',
            'refund' => 'Visszatérítések és javítások',
            'export' => 'Befizetések exportálása',
        ],
        'communications' => [
            'view_log' => 'Üzenetnapló megtekintése',
            'send' => 'Működési üzenetek küldése',
            'templates' => 'Üzenetsablonok szerkesztése',
        ],
        'campaigns' => [
            'view' => 'Kampányok megtekintése',
            'manage' => 'Kampányok létrehozása',
            'send' => 'Kampányok indítása',
        ],
        'reports' => [
            'view' => 'Jelentések megtekintése',
            'financial' => 'Pénzügyi jelentések megtekintése',
            'export' => 'Jelentések exportálása',
        ],
        'schedules' => [
            'view' => 'Munkarendek megtekintése',
            'manage' => 'Munkarendek és szabadságok módosítása',
        ],
        'catalog' => [
            'manage' => 'Telephelyek, szakemberek, szolgáltatások kezelése',
        ],
        'cms' => [
            'edit' => 'Oldalak szerkesztése',
            'publish' => 'Oldalak közzététele',
        ],
        'media' => [
            'manage' => 'Képek és fájlok kezelése',
        ],
        'seo' => [
            'manage' => 'SEO-beállítások és átirányítások',
        ],
        'translations' => [
            'edit' => 'Fordítások javítása',
            'publish' => 'Fordítások közzététele',
        ],
        'users' => [
            'view' => 'Felhasználók megtekintése',
            'manage' => 'Felhasználók kezelése',
        ],
        'roles' => [
            'manage' => 'Szerepkörök és jogosultságok kezelése',
        ],
        'settings' => [
            'manage' => 'Általános beállítások',
        ],
        'integrations' => [
            'manage' => 'Integrációk (email, SMS, WhatsApp)',
        ],
        'system' => [
            'updates' => 'Frissítések telepítése',
            'backups' => 'Biztonsági mentések',
            'diagnostics' => 'Rendszerdiagnosztika',
        ],
        'audit' => [
            'view' => 'Naplózás megtekintése',
        ],
    ],
];
