<?php

namespace App\Models;

use App\ReferenceMedicineStatus;
use App\StorageCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferenceMedicine extends Model
{
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'is_prescription' => 'boolean',
            'status' => ReferenceMedicineStatus::class,
            'allergen_tags' => 'array',
            'instructions' => 'array',
            'storage_condition' => StorageCondition::class,
            'days_after_opening' => 'integer',
        ];
    }
    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}
