<?php

namespace App\Models;

use App\NotificationLogType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'type' => NotificationLogType::class,
            'sent_at' => 'datetime',
        ];
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
