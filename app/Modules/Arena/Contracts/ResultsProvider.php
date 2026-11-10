<?php

namespace App\Modules\Arena\Contracts;

use App\Modules\Arena\Models\GameSession;

/**
 * Sursa automată de rezultate pentru o integrare viitoare (de exemplu, un API al platformei de joc, dacă va exista).
 * Proiectul nu are în prezent nicio sursă reală de scoruri: implementarea implicită nu întoarce nimic,
 * iar rezultatele se introduc manual din CRM. Rezultatele sincronizate se marchează „sync”, cele manuale „manual”.
 */
interface ResultsProvider
{
    /**
     * @return array<int, array{player_uuid:string, score?:int, time_ms?:int, team?:string}>|null null = nicio dată disponibilă
     */
    public function fetch(GameSession $session): ?array;
}
