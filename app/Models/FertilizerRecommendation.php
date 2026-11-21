<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FertilizerRecommendation extends Model
{
    use HasFactory;

    protected $table = 'fertilizer_recommendations';

    protected $fillable = [
        'reading_id',
        'crop',
        'predicted_fertilizer',
        'confidence',
        'top_predictions',
        'dose_kg_ha',
        'split_schedule',
        'rain_advisory',
        'model_version',
        'fallback_used',
    ];

    protected $casts = [
        'confidence' => 'float',
        'top_predictions' => 'array',
        'dose_kg_ha' => 'float',
        'split_schedule' => 'array',
        'rain_advisory' => 'array',
        'fallback_used' => 'boolean',
    ];

    public function reading(): BelongsTo
    {
        return $this->belongsTo(SensorReading::class, 'reading_id');
    }
}
