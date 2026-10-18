<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicalRecord extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['data'];

    protected $attributes = ['lock_version' => 1, 'status' => 'draft'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'examined_at' => 'datetime',
            'finalized_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ClinicalTemplate::class, 'template_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ClinicalRecordAmendment::class)->orderBy('id');
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function isFinal(): bool
    {
        return $this->status === 'final';
    }
}
