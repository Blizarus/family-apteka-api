<?php

namespace App\Models;

use App\MedicineBoxType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicineBox extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'box_type',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'box_type' => MedicineBoxType::class,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'medicine_box_users')
            ->using(MedicineBoxUser::class)
            ->withPivot('role');
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}
