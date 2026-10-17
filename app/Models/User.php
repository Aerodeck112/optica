<?php

namespace App\Models;

use App\Support\Access\AccessControl;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'is_active', 'all_locations'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /** Mirrors the column defaults so a freshly created model is never "inactive" by omission. */
    protected $attributes = [
        'locale' => 'ro',
        'is_active' => true,
        'all_locations' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'all_locations' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    public function specialist(): HasOne
    {
        return $this->hasOne(Specialist::class);
    }

    public function hasPermission(string $permission, Location|int|null $location = null): bool
    {
        return app(AccessControl::class)->allows($this, $permission, $location);
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, config('optica.locales'), true) ? $this->locale : config('app.fallback_locale');
    }
}
