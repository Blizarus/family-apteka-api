<?php

namespace App\Models;

use App\MedicineQuantityUnit;
use App\MedicineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'quantity_unit' => MedicineQuantityUnit::class,
            'status' => MedicineStatus::class,
            'quantity' => 'decimal:3',
            'expiration_date' => 'date',
            'opened_at' => 'date',
        ];
    }

    public function medicineBox(): BelongsTo
    {
        return $this->belongsTo(MedicineBox::class);
    }

    public function referenceMedicine(): BelongsTo
    {
        return $this->belongsTo(ReferenceMedicine::class);
    }

    public function intakeCourses(): HasMany
    {
        return $this->hasMany(IntakeCourse::class);
    }

    public function intakeLogs(): HasMany
    {
        return $this->hasMany(IntakeLog::class);
    }


}
