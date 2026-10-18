<?php

namespace App\Support\Ui;

use App\Models\User;

/**
 * Staff navigation. An entry appears only when the module exists and the user holds its permission;
 * the routes check the same permissions on the server (ACC-02).
 */
class Navigation
{
    /** @return list<array{label: string, items: list<array{label: string, route: string, active: string}>}> */
    public static function for(User $user): array
    {
        $groups = [
            [
                'label' => null,
                'items' => [
                    ['label' => 'crm.nav.dashboard', 'route' => 'crm.dashboard', 'active' => 'crm.dashboard', 'can' => null],
                    ['label' => 'crm.nav.calendar', 'route' => 'crm.calendar', 'active' => 'crm.calendar', 'can' => 'appointments.view'],
                ],
            ],
            [
                'label' => 'crm.nav.group_planning',
                'items' => [
                    ['label' => 'crm.nav.schedules', 'route' => 'admin.schedules.index', 'active' => 'admin.schedules.*', 'can' => 'schedules.view'],
                    ['label' => 'crm.nav.exceptions', 'route' => 'admin.exceptions.index', 'active' => 'admin.exceptions.*', 'can' => 'schedules.manage'],
                ],
            ],
            [
                'label' => 'crm.nav.group_admin',
                'items' => [
                    ['label' => 'crm.nav.locations', 'route' => 'admin.locations.index', 'active' => 'admin.locations.*', 'can' => 'catalog.manage'],
                    ['label' => 'crm.nav.specialists', 'route' => 'admin.specialists.index', 'active' => 'admin.specialists.*', 'can' => 'catalog.manage'],
                    ['label' => 'crm.nav.services', 'route' => 'admin.services.index', 'active' => 'admin.services.*', 'can' => 'catalog.manage'],
                    ['label' => 'crm.nav.users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'can' => 'users.view'],
                    ['label' => 'crm.nav.roles', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*', 'can' => 'roles.manage'],
                ],
            ],
        ];

        $result = [];
        foreach ($groups as $group) {
            $items = array_values(array_filter($group['items'], fn ($i) => $i['can'] === null || $user->can($i['can'])));
            if ($items) {
                $result[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $result;
    }
}
