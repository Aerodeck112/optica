<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/** Avatar cu inițiale generat local (fără servicii externe — nu trimitem numele utilizatorilor către terți). */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = (string) ($record->name ?? $record->email ?? '?');
        $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#2a1f5c"/><text x="32" y="40" font-family="sans-serif" font-size="24" font-weight="700" fill="#fff" text-anchor="middle">'.e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
