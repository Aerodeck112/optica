<?php

return [
    'modules' => [
        'patients' => 'Pacienți',
        'appointments' => 'Programări',
        'medical_records' => 'Fișe medicale',
        'prescriptions' => 'Prescripții',
        'documents' => 'Documente private',
        'leads' => 'Solicitări (leaduri)',
        'tasks' => 'Sarcini',
        'orders' => 'Comenzi de ochelari',
        'payments' => 'Încasări',
        'communications' => 'Comunicări',
        'campaigns' => 'Campanii',
        'reports' => 'Rapoarte',
        'schedules' => 'Orare de lucru',
        'catalog' => 'Locații, specialiști, servicii',
        'cms' => 'Pagini site',
        'media' => 'Bibliotecă media',
        'seo' => 'SEO',
        'translations' => 'Traduceri',
        'users' => 'Utilizatori',
        'roles' => 'Roluri',
        'settings' => 'Setări',
        'integrations' => 'Integrări',
        'system' => 'Sistem',
        'audit' => 'Jurnal de audit',
    ],
    'actions' => [
        'patients' => [
            'view' => 'Vezi pacienții',
            'create' => 'Adaugă pacienți',
            'update' => 'Modifică datele pacienților',
            'archive' => 'Arhivează pacienți',
            'export' => 'Exportă pacienți',
            'merge' => 'Unește dosare duplicate',
            'view_sensitive' => 'Vezi CNP-ul',
        ],
        'appointments' => [
            'view' => 'Vezi programările',
            'create' => 'Creează programări',
            'update' => 'Modifică și reprogramează',
            'cancel' => 'Anulează programări',
            'change_status' => 'Schimbă statusul (sosit, finalizat…)',
            'export' => 'Exportă programări',
        ],
        'medical_records' => [
            'view' => 'Vezi fișele din locațiile atribuite',
            'view_own' => 'Vezi fișele pacienților proprii',
            'create' => 'Completează fișe',
            'finalize' => 'Finalizează fișe',
            'amend' => 'Corectează fișe finalizate (amendament)',
            'export' => 'Exportă fișe',
        ],
        'prescriptions' => [
            'view' => 'Vezi prescripțiile',
            'create' => 'Emite prescripții',
            'amend' => 'Corectează prescripții',
        ],
        'documents' => [
            'view' => 'Vezi și descarcă documente',
            'upload' => 'Încarcă documente',
            'delete' => 'Șterge documente',
        ],
        'leads' => [
            'view' => 'Vezi solicitările',
            'manage' => 'Gestionează solicitările',
            'export' => 'Exportă solicitările',
        ],
        'tasks' => [
            'manage' => 'Gestionează sarcini',
        ],
        'orders' => [
            'view' => 'Vezi comenzile',
            'manage' => 'Creează și modifică comenzi',
            'change_status' => 'Schimbă statusul comenzilor',
            'export' => 'Exportă comenzi',
        ],
        'payments' => [
            'view' => 'Vezi încasările',
            'create' => 'Înregistrează încasări',
            'refund' => 'Rambursări și corecții',
            'export' => 'Exportă încasări',
        ],
        'communications' => [
            'view_log' => 'Vezi istoricul mesajelor',
            'send' => 'Trimite mesaje operaționale',
            'templates' => 'Editează șabloanele de mesaje',
        ],
        'campaigns' => [
            'view' => 'Vezi campaniile',
            'manage' => 'Creează campanii',
            'send' => 'Lansează campanii',
        ],
        'reports' => [
            'view' => 'Vezi rapoartele',
            'financial' => 'Vezi rapoartele financiare',
            'export' => 'Exportă rapoarte',
        ],
        'schedules' => [
            'view' => 'Vezi orarele',
            'manage' => 'Modifică orare și concedii',
        ],
        'catalog' => [
            'manage' => 'Administrează locații, specialiști, servicii',
        ],
        'cms' => [
            'edit' => 'Editează pagini',
            'publish' => 'Publică pagini',
        ],
        'media' => [
            'manage' => 'Gestionează imagini și fișiere',
        ],
        'seo' => [
            'manage' => 'Setări SEO și redirecționări',
        ],
        'translations' => [
            'edit' => 'Corectează traduceri',
            'publish' => 'Publică traduceri',
        ],
        'users' => [
            'view' => 'Vezi utilizatorii',
            'manage' => 'Gestionează utilizatori',
        ],
        'roles' => [
            'manage' => 'Gestionează roluri și permisiuni',
        ],
        'settings' => [
            'manage' => 'Setări generale',
        ],
        'integrations' => [
            'manage' => 'Integrări (email, SMS, WhatsApp)',
        ],
        'system' => [
            'updates' => 'Instalează actualizări',
            'backups' => 'Copii de siguranță',
            'diagnostics' => 'Diagnostic sistem',
        ],
        'audit' => [
            'view' => 'Vezi jurnalul de audit',
        ],
    ],
];
