<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

class Addon extends Model
{
    use HasTranslations;

    protected $table = 'addons';

    protected $guarded = ['id'];

    /** Valorile implicite din baza de date, disponibile și pe modelele abia create. */
    protected $attributes = ['pricing_model' => 'flat', 'vat_group' => 'standard', 'extra_minutes' => 0, 'max_quantity' => 1, 'is_active' => false, 'sort' => 0];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }
}
