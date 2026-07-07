<?php

namespace App\Models;

use App\ReferenceMedicineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferenceMedicine extends Model
{
    protected $guarded = [];
    protected function casts(): array{
        return [
            'is_prescription' => 'boolean',
            'status' => ReferenceMedicineStatus::class,
        ];
    }
    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}
