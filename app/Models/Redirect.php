<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_hit_at' => 'datetime'];
    }

    public static function normalize(string $path): string
    {
        $path = '/'.trim(parse_url($path, PHP_URL_PATH) ?? '', '/');

        return mb_strtolower(rawurldecode($path));
    }
}
