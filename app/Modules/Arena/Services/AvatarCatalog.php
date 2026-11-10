<?php

namespace App\Modules\Arena\Services;

use App\Modules\Arena\Models\Player;

/**
 * Colecția originală de avatare (SVG create pentru Another World Cluj, fără personaje din jocuri).
 * Fișierele stau local, în public/media/avatars; dacă unul lipsește, ecranul folosește avatarul neutru.
 * Nu se folosesc fotografii personale.
 */
final class AvatarCatalog
{
    public const CATEGORIES = [
        'explorer' => 'Exploratori spațiali',
        'robot' => 'Roboți',
        'astronaut' => 'Astronauți',
        'fantasy' => 'Personaje fantasy',
        'creature' => 'Creaturi cosmice',
        'mask' => 'Simboluri și măști',
    ];

    /** @var array<string, array{0:string,1:string}> cheie => [nume, categorie] */
    public const AVATARS = [
        'explorer-01' => ['Nova', 'explorer'], 'explorer-02' => ['Orion', 'explorer'], 'explorer-03' => ['Vega', 'explorer'],
        'explorer-04' => ['Lyra', 'explorer'], 'explorer-05' => ['Atlas', 'explorer'], 'explorer-06' => ['Sirius', 'explorer'],
        'robot-01' => ['Bolt', 'robot'], 'robot-02' => ['Cobalt', 'robot'], 'robot-03' => ['Pixel', 'robot'],
        'robot-04' => ['Rotor', 'robot'], 'robot-05' => ['Quark', 'robot'], 'robot-06' => ['Volt', 'robot'],
        'astronaut-01' => ['Apollo', 'astronaut'], 'astronaut-02' => ['Luna', 'astronaut'], 'astronaut-03' => ['Cometa', 'astronaut'],
        'astronaut-04' => ['Nebula', 'astronaut'], 'astronaut-05' => ['Zenit', 'astronaut'], 'astronaut-06' => ['Halo', 'astronaut'],
        'fantasy-01' => ['Elfa', 'fantasy'], 'fantasy-02' => ['Vrăjitorul', 'fantasy'], 'fantasy-03' => ['Cavalerul', 'fantasy'],
        'fantasy-04' => ['Druida', 'fantasy'], 'fantasy-05' => ['Zâna', 'fantasy'], 'fantasy-06' => ['Paladinul', 'fantasy'],
        'creature-01' => ['Glim', 'creature'], 'creature-02' => ['Nox', 'creature'], 'creature-03' => ['Zorb', 'creature'],
        'creature-04' => ['Krill', 'creature'], 'creature-05' => ['Flux', 'creature'], 'creature-06' => ['Orbi', 'creature'],
        'mask-01' => ['Cipher', 'mask'], 'mask-02' => ['Echo', 'mask'], 'mask-03' => ['Prisma', 'mask'],
        'mask-04' => ['Runa', 'mask'], 'mask-05' => ['Spectru', 'mask'], 'mask-06' => ['Vizor', 'mask'],
    ];

    public const NEUTRAL = 'neutral';

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::AVATARS[$key]);
    }

    public static function url(?string $key): string
    {
        return '/media/avatars/'.(self::exists($key) ? $key : self::NEUTRAL).'.svg';
    }

    public static function name(string $key): string
    {
        return self::AVATARS[$key][0] ?? 'Neutru';
    }

    /** @return array<string, string> cheie => „Nume (categorie)” pentru selectoare */
    public static function options(): array
    {
        $options = [];
        foreach (self::AVATARS as $key => [$name, $category]) {
            $options[$key] = $name.' · '.self::CATEGORIES[$category];
        }

        return $options;
    }

    /** Atribuirea automată: unul dintre avatarele cel mai puțin folosite, ales aleatoriu (varietate în aceeași sesiune). */
    public static function assign(): string
    {
        $used = Player::query()->selectRaw('avatar_key, count(*) as total')->groupBy('avatar_key')->pluck('total', 'avatar_key')->all();
        $counts = [];
        foreach (array_keys(self::AVATARS) as $key) {
            $counts[$key] = (int) ($used[$key] ?? 0);
        }
        $min = min($counts);
        $candidates = array_keys(array_filter($counts, fn ($c) => $c === $min));

        return $candidates[array_rand($candidates)];
    }
}
