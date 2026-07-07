<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    protected $guarded = [];
    public function casts(): array
    {
        return[
            'birth_date' => 'date',
        ];
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function intakeCourses(): HasMany
    {
        return $this->hasMany(IntakeCourse::class);
    }
}
