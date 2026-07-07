<?php

namespace App\Models;

use App\ReferenceMedicineProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceMedicineProposal extends Model
{
    protected $guarded = [];

    protected function casts(): array{
        return [
            'status' => ReferenceMedicineProposalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
