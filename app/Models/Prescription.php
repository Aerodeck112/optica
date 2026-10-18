<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    public const TYPES = ['glasses', 'contact_lenses'];

    protected $guarded = ['id'];

    protected $hidden = ['data'];

    protected $attributes = ['version' => 1, 'status' => 'active'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'issued_on' => 'date', 'valid_until' => 'date', 'version' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'supersedes_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'clinical_record_id');
    }
}
