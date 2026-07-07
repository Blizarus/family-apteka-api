<?php

namespace App\Models;

use App\MedicineBoxType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicineBox extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'box_type' => MedicineBoxType::class,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}
