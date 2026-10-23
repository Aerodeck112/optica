<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Autorizare pe permisiuni pentru resursele Filament. Resursa definește:
 *  - static string $viewPermission
 *  - static ?string $managePermission (implicit = $viewPermission)
 *  - opțional static ?string $deletePermission (null = ștergere interzisă)
 */
trait ChecksPermissions
{
    /** Etichetele în română nu se scriu cu Majuscule La Fiecare Cuvânt. */
    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    protected static function userCan(?string $permission): bool
    {
        return $permission !== null && (bool) auth()->user()?->can($permission);
    }

    protected static function managePermissionName(): ?string
    {
        return property_exists(static::class, 'managePermission') ? static::$managePermission : static::$viewPermission;
    }

    protected static function deletePermissionName(): ?string
    {
        return property_exists(static::class, 'deletePermission') ? static::$deletePermission : static::managePermissionName();
    }

    public static function canViewAny(): bool
    {
        return static::userCan(static::$viewPermission);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::userCan(static::managePermissionName());
    }

    public static function canEdit(Model $record): bool
    {
        return static::userCan(static::managePermissionName());
    }

    public static function canDelete(Model $record): bool
    {
        return static::userCan(static::deletePermissionName());
    }

    public static function canDeleteAny(): bool
    {
        return static::userCan(static::deletePermissionName());
    }
}
