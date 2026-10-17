<?php

namespace App\Support\Access;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deny-by-default permission resolution with location scoping (ACC-01, D-007).
 * Results are memoised for the current request/process; call flush() after changing assignments.
 */
class AccessControl
{
    /** @var array<int, array{permissions: array<string, true>, locations: list<int>, all: bool}> */
    private array $cache = [];

    public function allows(?User $user, string $permission, Location|int|null $location = null): bool
    {
        if ($user === null || ! $user->is_active || $user->trashed()) {
            return false;
        }

        $grants = $this->grants($user);
        if (! isset($grants['permissions'][$permission])) {
            return false;
        }

        if ($location === null) {
            return true;
        }

        $locationId = $location instanceof Location ? $location->getKey() : $location;

        return $grants['all'] || in_array($locationId, $grants['locations'], true);
    }

    /**
     * Location IDs where the user holds the permission. Null means every location.
     *
     * @return list<int>|null
     */
    public function locationIdsFor(?User $user, string $permission): ?array
    {
        if ($user === null || ! $this->allows($user, $permission)) {
            return [];
        }

        $grants = $this->grants($user);

        return $grants['all'] ? null : $grants['locations'];
    }

    /** @return list<string> */
    public function permissionsFor(User $user): array
    {
        return array_keys($this->grants($user)['permissions']);
    }

    public function flush(?User $user = null): void
    {
        if ($user === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$user->getKey()]);
        }
    }

    private function grants(User $user): array
    {
        return $this->cache[$user->getKey()] ??= [
            'permissions' => array_fill_keys(
                DB::table('role_user')
                    ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('role_user.user_id', $user->getKey())
                    ->distinct()
                    ->pluck('permissions.key')
                    ->all(),
                true,
            ),
            'locations' => DB::table('location_user')
                ->where('user_id', $user->getKey())
                ->pluck('location_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'all' => (bool) $user->all_locations,
        ];
    }
}
