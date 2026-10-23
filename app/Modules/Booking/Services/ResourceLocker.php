<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Resource;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Serializează operațiile care ocupă timp pe aceleași resurse.
 *
 * - Tranzacția rulează în READ COMMITTED (MySQL/MariaDB): după obținerea blocării, verificările
 *   de suprapunere văd tot ce au confirmat tranzacțiile anterioare, fără gap-locks care ar produce deadlock.
 * - Rândurile resurselor (familia completă) se blochează cu SELECT … FOR UPDATE, în ordinea id-ului.
 * - Deadlock-urile ocazionale sunt reîncercate automat.
 */
class ResourceLocker
{
    /**
     * @template T
     *
     * @param  array<int>  $resourceIds
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(array $resourceIds, Closure $callback): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $resourceIds)));
        sort($ids);

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && $connection->transactionLevel() === 0) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $connection->transaction(function () use ($ids, $callback) {
            if ($ids) {
                Resource::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            return $callback();
        }, 5);
    }
}
