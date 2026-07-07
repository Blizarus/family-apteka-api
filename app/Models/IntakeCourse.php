<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeCourse extends Model
{
    protected $guarded = [];
    protected function casts(): array{
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'dosage_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function intakeLogs(): HasMany
    {
        return $this->hasMany(IntakeLog::class);
    }

    public function intakeSchedules(): HasMany
    {
        return $this->hasMany(IntakeSchedule::class);
    }
}
