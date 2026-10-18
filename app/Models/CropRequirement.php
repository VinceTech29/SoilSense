<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropRequirement extends Model
{
    use HasFactory;

    protected $table = 'crop_requirements';

    protected $fillable = [
        'crop_profile_id',
        'min_ph',
        'max_ph',
        'optimal_ph_min',
        'optimal_ph_max',
        'min_moisture',
        'max_moisture',
        'optimal_moisture_min',
        'optimal_moisture_max',
        'moisture_unit',
        'min_temperature',
        'max_temperature',
        'optimal_temperature_min',
        'optimal_temperature_max',
        'max_ec',
        'ec_unit',
        'source_citation',
        'source_url',
        'applicability_notes',
        'limitations_notes',
    ];

    protected $casts = [
        'min_ph' => 'float',
        'max_ph' => 'float',
        'optimal_ph_min' => 'float',
        'optimal_ph_max' => 'float',
        'min_moisture' => 'float',
        'max_moisture' => 'float',
        'optimal_moisture_min' => 'float',
        'optimal_moisture_max' => 'float',
        'min_temperature' => 'float',
        'max_temperature' => 'float',
        'optimal_temperature_min' => 'float',
        'optimal_temperature_max' => 'float',
        'max_ec' => 'float',
    ];

    public function crop(): BelongsTo
    {
        return $this->belongsTo(CropProfile::class, 'crop_profile_id');
    }
}
