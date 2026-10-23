<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Tablou de bord';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('dashboard.view');
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
