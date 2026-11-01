<?php

namespace App\Models;

use App\ReferenceMedicineProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceMedicineProposal extends Model
{
    protected $fillable = [
        'user_id',
        'barcode',
        'raw_barcode',
        'raw_trade_name',
        'raw_manufacturer',
        'raw_form',
        'raw_dosage',
        'image_url',
        'status',
        'admin_comment',
        'admin_notes',
        'reviewed_at',
        'approved_at',
        'approved_by_user_id',
    ];

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
