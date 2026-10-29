<?php

namespace App\Models;

use App\MedicineBoxUserRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MedicineBoxUser extends Pivot
{
    protected $table = 'medicine_box_users';
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'role' => MedicineBoxUserRole::class,
        ];
    }

    public function medicineBox(): BelongsTo
    {
        return $this->belongsTo(MedicineBox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
