<?php

namespace App\Models;

use App\ReferenceMedicineStatus;
use App\StorageCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferenceMedicine extends Model
{
    protected $fillable = [
        'trade_name',
        'inn',
        'dosage_form',
        'dosage',
        'pack_size',
        'manufacturer',
        'country',
        'atc_code',
        'barcode',
        'is_prescription',
        'storage_condition',
        'days_after_opening',
        'image_url',
        'leaflet_pdf_url',
        'status',
        'instructions',
        'composition',
        'allergen_tags',
        'chronic_contraindications',
    ];
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
