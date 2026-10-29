<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'birth_date',
        'avatar_url',
        'notes',
        'allergies',
        'chronic_conditions',
    ];

    public function casts(): array
    {
        return [
            'birth_date' => 'date',
            'notes' => 'encrypted', // Шифрование чувствительных медицинских заметок алгоритмом AES-256 (Data at Rest)
            'allergies' => 'array',
            'chronic_conditions' => 'array',
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
