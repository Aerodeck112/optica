<?php

namespace App\Support\Access;

/**
 * Stable permission identifiers (LNG-03). Labels live in lang/{ro,hu}/permissions.php.
 * See PERMISSIONS.md for the default role matrix.
 */
final class PermissionCatalog
{
    public const PERMISSIONS = [
        'patients' => ['view', 'create', 'update', 'archive', 'export', 'merge', 'view_sensitive'],
        'appointments' => ['view', 'create', 'update', 'cancel', 'change_status', 'export'],
        'medical_records' => ['view', 'view_own', 'create', 'finalize', 'amend', 'export', 'templates'],
        'prescriptions' => ['view', 'create', 'amend'],
        'documents' => ['view', 'upload', 'delete'],
        'leads' => ['view', 'manage', 'export'],
        'tasks' => ['manage'],
        'orders' => ['view', 'manage', 'change_status', 'export'],
        'payments' => ['view', 'create', 'refund', 'export'],
        'communications' => ['view_log', 'send', 'templates'],
        'campaigns' => ['view', 'manage', 'send'],
        'reports' => ['view', 'financial', 'export'],
        'schedules' => ['view', 'manage'],
        'catalog' => ['manage'],
        'cms' => ['edit', 'publish'],
        'media' => ['manage'],
        'seo' => ['manage'],
        'translations' => ['edit', 'publish'],
        'users' => ['view', 'manage'],
        'roles' => ['manage'],
        'settings' => ['manage'],
        'integrations' => ['manage'],
        'system' => ['updates', 'backups', 'diagnostics'],
        'audit' => ['view'],
    ];

    /** Permissions never granted implicitly, even to the superadministrator (cap. 5). */
    public const CLINICAL = [
        'medical_records.view', 'medical_records.view_own', 'medical_records.create',
        'medical_records.finalize', 'medical_records.amend', 'medical_records.export', 'medical_records.templates',
        'prescriptions.view', 'prescriptions.create', 'prescriptions.amend',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $keys = [];
        foreach (self::PERMISSIONS as $module => $actions) {
            foreach ($actions as $action) {
                $keys[] = $module.'.'.$action;
            }
        }

        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    public static function module(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /**
     * Default roles (PERMISSIONS.md). Location scoping comes from the user's assignments.
     *
     * @return array<string, array{name: array{ro: string, hu: string}, permissions: list<string>}>
     */
    public static function defaultRoles(): array
    {
        $nonClinical = array_values(array_diff(self::all(), self::CLINICAL));

        return [
            'superadmin' => [
                'name' => ['ro' => 'Superadministrator', 'hu' => 'Szuperadminisztrátor'],
                'permissions' => $nonClinical,
            ],
            'operations_admin' => [
                'name' => ['ro' => 'Administrator operațional', 'hu' => 'Operatív adminisztrátor'],
                'permissions' => [
                    'patients.view', 'patients.create', 'patients.update', 'patients.archive', 'patients.export', 'patients.merge',
                    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'appointments.change_status', 'appointments.export',
                    'leads.view', 'leads.manage', 'leads.export', 'tasks.manage',
                    'orders.view', 'orders.manage', 'orders.change_status', 'orders.export',
                    'payments.view', 'payments.create',
                    'communications.view_log', 'communications.send', 'communications.templates',
                    'reports.view', 'reports.export',
                    'schedules.view', 'schedules.manage', 'catalog.manage',
                ],
            ],
            'location_manager' => [
                'name' => ['ro' => 'Manager locație', 'hu' => 'Telephelyvezető'],
                'permissions' => [
                    'patients.view', 'patients.create', 'patients.update', 'patients.view_sensitive', 'patients.merge', 'patients.export',
                    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'appointments.change_status', 'appointments.export',
                    'documents.view', 'documents.upload',
                    'leads.view', 'leads.manage', 'tasks.manage',
                    'orders.view', 'orders.manage', 'orders.change_status', 'orders.export',
                    'payments.view', 'payments.create', 'payments.refund', 'payments.export',
                    'communications.view_log', 'communications.send',
                    'reports.view', 'reports.financial', 'reports.export',
                    'schedules.view', 'schedules.manage',
                ],
            ],
            'reception' => [
                'name' => ['ro' => 'Recepție', 'hu' => 'Recepció'],
                'permissions' => [
                    'patients.view', 'patients.create', 'patients.update', 'patients.view_sensitive',
                    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'appointments.change_status',
                    'documents.view', 'documents.upload',
                    'leads.view', 'leads.manage', 'tasks.manage',
                    'orders.view', 'orders.manage', 'orders.change_status',
                    'payments.view', 'payments.create',
                    'communications.view_log', 'communications.send',
                    'schedules.view',
                ],
            ],
            'specialist' => [
                'name' => ['ro' => 'Medic / Optometrist', 'hu' => 'Orvos / Optometrista'],
                'permissions' => [
                    'patients.view', 'patients.create', 'patients.update', 'patients.view_sensitive',
                    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'appointments.change_status',
                    'medical_records.view_own', 'medical_records.create', 'medical_records.finalize', 'medical_records.amend',
                    'prescriptions.view', 'prescriptions.create', 'prescriptions.amend',
                    'documents.view', 'documents.upload',
                    'tasks.manage', 'orders.view', 'orders.manage', 'orders.change_status',
                    'schedules.view',
                ],
            ],
            'assistant' => [
                'name' => ['ro' => 'Asistent', 'hu' => 'Asszisztens'],
                'permissions' => [
                    'patients.view', 'patients.create', 'patients.update',
                    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel', 'appointments.change_status',
                    'documents.view', 'documents.upload',
                    'tasks.manage', 'orders.view', 'orders.manage', 'orders.change_status',
                    'schedules.view',
                ],
            ],
            'marketing' => [
                'name' => ['ro' => 'Marketing', 'hu' => 'Marketing'],
                'permissions' => [
                    'leads.view', 'leads.manage', 'leads.export', 'tasks.manage',
                    'communications.templates', 'campaigns.view', 'campaigns.manage', 'campaigns.send',
                    'reports.view', 'reports.export',
                    'cms.edit', 'media.manage', 'seo.manage',
                ],
            ],
            'finance' => [
                'name' => ['ro' => 'Financiar', 'hu' => 'Pénzügy'],
                'permissions' => [
                    'patients.view', 'orders.view',
                    'payments.view', 'payments.create', 'payments.refund', 'payments.export',
                    'reports.view', 'reports.financial', 'reports.export',
                ],
            ],
            'site_editor' => [
                'name' => ['ro' => 'Editor site', 'hu' => 'Weboldal-szerkesztő'],
                'permissions' => [
                    'cms.edit', 'cms.publish', 'media.manage', 'seo.manage',
                    'translations.edit', 'translations.publish',
                ],
            ],
        ];
    }
}
