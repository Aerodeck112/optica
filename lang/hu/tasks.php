<?php

return [
    'title' => 'Feladatok',
    'scope' => 'Megjelenített feladatok',
    'scopes' => ['mine' => 'Saját', 'team' => 'Telephelyeim'],
    'filters' => ['open' => 'Teendő', 'done' => 'Elvégzett', 'all' => 'Összes'],
    'create' => 'Új feladat',
    'fields' => ['title' => 'Teendő', 'description' => 'Részletek', 'due_at' => 'Határidő', 'assigned_to' => 'Felelős', 'is_urgent' => 'Sürgős'],
    'no_clinical' => 'Csak adminisztratív információ, egészségügyi adatok nélkül.',
    'urgent' => 'Sürgős',
    'due' => 'Határidő: :date',
    'unassigned' => 'Nincs felelős',
    'mark_done' => '„:title” megjelölése elvégzettként',
    'reopen' => 'Újranyitás',
    'cancel' => 'Visszavonás',
    'confirm_cancel' => 'Visszavonja a feladatot?',
    'none' => 'Nincsenek feladatok.',
];
