<?php

namespace App\Models;

use App\MedicineQuantityUnit;
use App\MedicineStatus;
use App\StorageCondition;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $fillable = [
        'medicine_box_id',
        'reference_medicine_id',
        'custom_trade_name',
        'barcode',
        'quantity',
        'quantity_unit',
        'expiration_date',
        'opened_at',
        'days_after_opening',
        'storage_condition',
        'low_stock_threshold',
        'status',
        'notes',
    ];

    protected $appends = [
        'effective_expiration_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity_unit' => MedicineQuantityUnit::class,
            'status' => MedicineStatus::class,
            'quantity' => 'decimal:3',
            'expiration_date' => 'date',
            'opened_at' => 'date',
            'storage_condition' => StorageCondition::class,
            'days_after_opening' => 'integer',
            'low_stock_threshold' => 'decimal:3',
            'notes' => 'encrypted', // Шифрование чувствительных медицинских примечаний (AES-256)
            'custom_trade_name' => 'encrypted', // Защита персональных названий препаратов в покое (Data at Rest AES-256)
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

    /**
     * Вычисляемый эффективный срок годности (модель FEFO-Opened):
     * Возвращает минимальную дату между заводским сроком годности (expiration_date)
     * и сроком годности после вскрытия упаковки (opened_at + days_after_opening).
     */
    public function getEffectiveExpirationDateAttribute(): ?Carbon
    {
        $daysAfterOpening = $this->days_after_opening ?? $this->referenceMedicine?->days_after_opening;

        $openedExpiry = null;
        if ($this->opened_at && $daysAfterOpening !== null) {
            $openedExpiry = Carbon::parse($this->opened_at)->startOfDay()->addDays((int) $daysAfterOpening);
        }

        $packageExpiry = $this->expiration_date ? Carbon::parse($this->expiration_date)->startOfDay() : null;

        if ($openedExpiry && $packageExpiry) {
            return $openedExpiry->lessThan($packageExpiry) ? $openedExpiry : $packageExpiry;
        }

        return $openedExpiry ?? $packageExpiry;
    }
}
