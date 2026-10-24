<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\IntegrationResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrations extends ListRecords
{
    protected static string $resource = IntegrationResource::class;

    public function getSubheading(): ?string
    {
        return 'Aici se administrează conturile externe (e-mail, SMS, plăți, AI, ANAF, casă de marcat). Parolele și cheile sunt criptate și nu se afișează niciodată.';
    }
}
