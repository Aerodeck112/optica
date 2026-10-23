<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $table = 'testimonials';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reviewed_on' => 'immutable_date',
            'is_published' => 'boolean',
            'publication_consent' => 'boolean',
        ];
    }
}
