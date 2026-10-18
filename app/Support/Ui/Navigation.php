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
                    ['label' => 'crm.nav.patients', 'route' => 'crm.patients.index', 'active' => 'crm.patients.*|crm.records.*', 'can' => 'patients.view'],
                    ['label' => 'crm.nav.leads', 'route' => 'crm.leads.index', 'active' => 'crm.leads.*', 'can' => 'leads.view'],
                    ['label' => 'crm.nav.tasks', 'route' => 'crm.tasks', 'active' => 'crm.tasks', 'can' => 'tasks.manage'],
                    ['label' => 'crm.nav.orders', 'route' => 'crm.orders.index', 'active' => 'crm.orders.*', 'can' => 'orders.view'],
                    ['label' => 'crm.nav.payments', 'route' => 'crm.payments.index', 'active' => 'crm.payments.*', 'can' => 'payments.view'],
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
                'label' => 'crm.nav.group_communication',
                'items' => [
                    ['label' => 'crm.nav.messages', 'route' => 'crm.messages.index', 'active' => 'crm.messages.*', 'can' => 'communications.view_log'],
                    ['label' => 'crm.nav.campaigns', 'route' => 'crm.campaigns', 'active' => 'crm.campaigns', 'can' => 'campaigns.view'],
                    ['label' => 'crm.nav.message_templates', 'route' => 'admin.communication.templates', 'active' => 'admin.communication.templates', 'can' => 'communications.templates'],
                    ['label' => 'crm.nav.message_rules', 'route' => 'admin.communication.rules', 'active' => 'admin.communication.rules', 'can' => 'communications.templates'],
                    ['label' => 'crm.nav.channels', 'route' => 'admin.communication.channels', 'active' => 'admin.communication.channels', 'can' => 'integrations.manage'],
                ],
            ],
            [
                'label' => 'crm.nav.group_site',
                'items' => [
                    ['label' => 'crm.nav.pages', 'route' => 'cms.pages.index', 'active' => 'cms.pages.*', 'can' => 'cms.edit'],
                    ['label' => 'crm.nav.offers', 'route' => 'cms.offers.index', 'active' => 'cms.offers.*', 'can' => 'cms.edit'],
                    ['label' => 'crm.nav.media', 'route' => 'cms.media', 'active' => 'cms.media', 'can' => 'media.manage'],
                    ['label' => 'crm.nav.menus', 'route' => 'cms.menus', 'active' => 'cms.menus', 'can' => 'cms.publish'],
                    ['label' => 'crm.nav.redirects', 'route' => 'cms.redirects', 'active' => 'cms.redirects', 'can' => 'seo.manage'],
                    ['label' => 'crm.nav.site_settings', 'route' => 'cms.settings', 'active' => 'cms.settings', 'can' => 'cms.publish'],
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
                    ['label' => 'crm.nav.clinical_templates', 'route' => 'admin.clinical-templates', 'active' => 'admin.clinical-templates', 'can' => 'medical_records.templates'],
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
