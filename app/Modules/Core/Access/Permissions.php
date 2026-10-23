<?php

namespace App\Modules\Core\Access;

/**
 * Matricea rol → permisiuni. Verificată server-side în politici și în panou pentru fiecare operațiune.
 */
final class Permissions
{
    public const ALL = [
        'dashboard.view' => 'Vede tabloul de bord',
        'bookings.view' => 'Vede rezervările',
        'bookings.manage' => 'Creează și modifică rezervări',
        'bookings.cancel' => 'Anulează rezervări',
        'bookings.checkin' => 'Face check-in',
        'resources.manage' => 'Administrează resurse și blocări',
        'customers.view' => 'Vede clienții',
        'customers.manage' => 'Modifică clienți',
        'customers.private_notes' => 'Vede notele cu acces restricționat',
        'customers.merge' => 'Unește clienți duplicați',
        'customers.export' => 'Exportă / anonimizează date personale',
        'leads.manage' => 'Gestionează cereri și oportunități',
        'tasks.manage' => 'Gestionează sarcini',
        'cms.manage' => 'Editează conținutul site-ului',
        'cms.publish' => 'Publică conținut',
        'legal.manage' => 'Administrează documente juridice',
        'catalog.manage' => 'Administrează jocuri și pachete',
        'prices.manage' => 'Modifică prețuri',
        'marketing.manage' => 'Creează campanii și segmente',
        'marketing.send' => 'Programează/trimite campanii',
        'templates.manage' => 'Editează șabloane de mesaje',
        'messages.view' => 'Vede jurnalul de mesaje',
        'loyalty.manage' => 'Administrează fidelizarea',
        'vouchers.manage' => 'Emite și administrează vouchere',
        'finance.view' => 'Vede documente financiare',
        'finance.issue' => 'Emite facturi și încasări',
        'finance.refund' => 'Aprobă rambursări',
        'finance.export' => 'Exportă pentru contabilitate',
        'efactura.manage' => 'Administrează e-Factura',
        'inventory.manage' => 'Administrează furnizori, recepții și stocuri',
        'fiscal.operate' => 'Emite bonuri fiscale',
        'fiscal.reconcile' => 'Reconciliază bonuri incerte',
        'reports.view' => 'Vede rapoarte',
        'ai.manage' => 'Administrează asistentul AI',
        'integrations.manage' => 'Administrează integrările și credențialele',
        'settings.manage' => 'Administrează setările locației',
        'users.manage' => 'Administrează utilizatorii',
        'audit.view' => 'Vede jurnalul de audit',
        'backups.manage' => 'Gestionează backup-uri',
    ];

    public const ROLES = [
        'super_admin' => ['label' => 'Super administrator', 'permissions' => ['*']],
        'manager' => ['label' => 'Manager', 'permissions' => [
            'dashboard.view', 'bookings.*', 'resources.manage', 'customers.*', 'leads.manage', 'tasks.manage',
            'cms.manage', 'cms.publish', 'legal.manage', 'catalog.manage', 'prices.manage', 'marketing.*', 'templates.manage',
            'messages.view', 'loyalty.manage', 'vouchers.manage', 'finance.view', 'finance.issue', 'finance.refund',
            'inventory.manage', 'fiscal.*', 'reports.view', 'ai.manage', 'audit.view',
        ]],
        'reception' => ['label' => 'Recepție', 'permissions' => [
            'dashboard.view', 'bookings.view', 'bookings.manage', 'bookings.cancel', 'bookings.checkin',
            'customers.view', 'customers.manage', 'leads.manage', 'tasks.manage', 'vouchers.manage', 'finance.view', 'finance.issue', 'fiscal.operate',
        ]],
        'operator' => ['label' => 'Operator arenă', 'permissions' => ['dashboard.view', 'bookings.view', 'bookings.checkin', 'tasks.manage']],
        'marketing' => ['label' => 'Marketing', 'permissions' => ['dashboard.view', 'customers.view', 'marketing.*', 'templates.manage', 'messages.view', 'cms.manage', 'cms.publish', 'reports.view', 'loyalty.manage']],
        'accountant' => ['label' => 'Contabil', 'permissions' => ['finance.view', 'finance.export', 'efactura.manage', 'inventory.manage', 'reports.view', 'fiscal.reconcile']],
        'editor' => ['label' => 'Editor conținut', 'permissions' => ['cms.manage', 'catalog.manage']],
    ];

    /** @return array<int, string> */
    public static function forRole(string $role): array
    {
        $patterns = self::ROLES[$role]['permissions'] ?? [];
        $all = array_keys(self::ALL);
        if ($patterns === ['*']) {
            return $all;
        }

        return array_values(array_filter($all, function ($permission) use ($patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern === $permission || (str_ends_with($pattern, '.*') && str_starts_with($permission, substr($pattern, 0, -1)))) {
                    return true;
                }
            }

            return false;
        }));
    }
}
