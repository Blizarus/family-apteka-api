<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    protected $fillable = [
        'email',
        'password',
        'timezone',
        'sync_enabled',
        'consent_given_at',
        'consent_version',
        'is_admin',
        'is_blocked',
        'blocked_at',
        'fcm_token',
        'device_platform',
    ];
    protected $hidden = ['password'];
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'sync_enabled' => 'boolean',
            'consent_given_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_blocked' => 'boolean',
            'blocked_at' => 'datetime',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function medicineBoxes(): BelongsToMany
    {
        return $this->belongsToMany(MedicineBox::class, 'medicine_box_users')
            ->using(MedicineBoxUser::class)
            ->withPivot('role');
    }

    public function referenceMedicineProposals(): HasMany
    {
        return $this->hasMany(ReferenceMedicineProposal::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }


}
