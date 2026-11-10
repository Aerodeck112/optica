<?php

namespace App\Modules\Arena\Models;

use Illuminate\Database\Eloquent\Model;

/** Nickname respins de recepție/manager: forma normalizată nu mai poate fi folosită de nimeni. */
class NicknameBlock extends Model
{
    protected $table = 'nickname_blocks';

    protected $guarded = ['id'];
}
