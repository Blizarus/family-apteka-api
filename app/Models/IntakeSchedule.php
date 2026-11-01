<?php

namespace App\Models;

use App\IntakeScheduleFrequencyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeSchedule extends Model
{
    protected $fillable = [
        'intake_course_id',
        'frequency_type',
        'time_slots',
        'interval_hours',
        'interval_days',
        'days_of_week',
        'valid_from',
        'valid_until',
    ];
    protected function casts(): array{
        return [
            'frequency_type' => IntakeScheduleFrequencyType::class,
            'time_slots' => 'array',
            'interval_hours' => 'integer',
            'days_of_week' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
    public function intakeCourse(): BelongsTo
    {
        return $this->belongsTo(IntakeCourse::class);
    }
    public function intakeLogs(): HasMany
    {
        return $this->hasMany(IntakeLog::class);
    }
}
