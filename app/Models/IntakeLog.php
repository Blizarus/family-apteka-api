<?php

namespace App\Models;

use App\IntakeLogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_time' => 'datetime',
            'taken_time' => 'datetime',
            'client_created_at' => 'datetime',
            'quantity_taken' => 'decimal:3',
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
