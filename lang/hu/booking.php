<?php

return [
    'errors' => [
        'slot_unavailable' => 'A kiválasztott időpont már nem elérhető. Kérjük, válasszon másik időpontot.',
        'outside_schedule' => 'Az időpont kívül esik a szakember munkaidején.',
        'specialist_busy' => 'A szakembernek ebben az időpontban már van foglalása.',
        'specialist_other_location' => 'A szakember ebben az időpontban másik rendelőben dolgozik (az utazási időt is beleszámítva).',
        'capacity_reached' => 'Erre az időpontra elérte a maximális kapacitást.',
        'resource_busy' => 'A szükséges rendelő vagy eszköz ebben az időpontban foglalt.',
        'online_disabled' => 'Az online időpontfoglalás ennél a választásnál nem érhető el.',
        'too_soon' => 'Az időpont túl közel van az online foglaláshoz.',
        'too_far' => 'Az időpont túllépi az online foglalás maximális időszakát.',
        'in_past' => 'Múltbeli időpontra nem lehet foglalni.',
        'invalid_transition' => 'Ez az állapotváltás nem engedélyezett.',
        'concurrent_modification' => 'A foglalást időközben valaki más módosította. Töltse újra az oldalt, és próbálja meg ismét.',
        'hold_expired' => 'Az ideiglenes foglalás lejárt. Kérjük, válassza ki újra az időpontot.',
        'offering_inactive' => 'A szolgáltatás nem aktív ennél a szakembernél és rendelőben.',
    ],
];
