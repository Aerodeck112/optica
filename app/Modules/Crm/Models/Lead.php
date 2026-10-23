<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Package;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oportunitate pentru aniversări / evenimente private / team building.
 * O cerere nu ocupă arena; la confirmare se transformă în rezervare (fără dublarea datelor).
 */
class Lead extends Model
{
    public const STAGES = [
        'new' => 'Cerere',
        'contacted' => 'Contactat',
        'offer_sent' => 'Ofertă trimisă',
        'negotiating' => 'În negociere',
        'confirmed' => 'Confirmat',
        'completed' => 'Finalizat',
        'lost' => 'Pierdut',
    ];

    public const TYPES = [
        'birthday' => 'Aniversare',
        'corporate' => 'Team building / corporate',
        'private' => 'Eveniment privat',
        'group' => 'Grup de prieteni',
        'other' => 'Altă cerere',
    ];

    protected $table = 'leads';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'immutable_date',
            'alternative_dates' => 'array',
            'age_groups' => 'array',
            'stage_changed_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('created_at');
    }

    public function isClosed(): bool
    {
        return in_array($this->stage, ['completed', 'lost'], true);
    }
}
