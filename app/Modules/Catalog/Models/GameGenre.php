<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class GameGenre extends Model
{
    use HasTranslations;

    protected $table = 'game_genres';

    protected $guarded = ['id'];

    public array $translatable = ['name'];

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'genre_id');
    }
}
