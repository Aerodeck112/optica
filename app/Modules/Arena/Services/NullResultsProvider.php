<?php

namespace App\Modules\Arena\Services;

use App\Modules\Arena\Contracts\ResultsProvider;
use App\Modules\Arena\Models\GameSession;

/** Nu există integrare de scoruri: nu se inventează rezultate. */
class NullResultsProvider implements ResultsProvider
{
    public function fetch(GameSession $session): ?array
    {
        return null;
    }
}
