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
            'allergies' => 'encrypted:array', // Специальные категории ПДн (ст. 10 152-ФЗ) - AES-256
            'chronic_conditions' => 'encrypted:array', // Диагнозы и коморбидности - AES-256
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
