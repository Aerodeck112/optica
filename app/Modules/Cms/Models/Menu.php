<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $table = 'menus';

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id')->where('is_visible', true)->orderBy('sort');
    }
}
