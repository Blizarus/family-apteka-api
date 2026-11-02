<?php

namespace App\Models;

use App\IntakeLogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeLog extends Model
{
    protected $fillable = [
        'intake_course_id',
        'intake_schedule_id',
        'medicine_id',
        'scheduled_time',
        'taken_time',
        'client_created_at',
        'quantity_taken',
        'deducted_quantity',
        'status',
        'skipped_reason',
        'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_time' => 'datetime',
            'taken_time' => 'datetime',
            'client_created_at' => 'datetime',
            'quantity_taken' => 'decimal:3',
            'deducted_quantity' => 'decimal:3',
            'status' => IntakeLogStatus::class,
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function intakeCourse(): BelongsTo
    {
        return $this->belongsTo(IntakeCourse::class);
    }

    public function intakeSchedule(): BelongsTo
    {
        return $this->belongsTo(IntakeSchedule::class);
    }
}
