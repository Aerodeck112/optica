<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class ScheduleException extends Model
{
    use HasTranslations;

    protected $table = 'schedule_exceptions';

    protected $guarded = ['id'];

    public array $translatable = ['public_note'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'is_closed' => 'boolean',
        ];
    }
}
