<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SensorReading extends Model
{
    use HasFactory;

    protected $table = 'sensor_readings';

    protected $fillable = [
        'soil_sample_id',
        'device_id',
        'ph',
        'moisture',
        'moisture_unit',
        'temperature',
        'ec',
        'ec_unit',
        'nitrogen',
        'phosphorus',
        'potassium',
        'npk_source',
        'is_valid',
        'validity_notes',
        'calibration_metadata',
        'recorded_at',
        'data_source',
    ];

    protected $casts = [
        'ph' => 'float',
        'moisture' => 'float',
        'temperature' => 'float',
        'ec' => 'float',
        'nitrogen' => 'float',
        'phosphorus' => 'float',
        'potassium' => 'float',
        'is_valid' => 'boolean',
        'calibration_metadata' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function soilSample(): BelongsTo
    {
        return $this->belongsTo(SoilSample::class, 'soil_sample_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(IotDevice::class, 'device_id');
    }

    public function fertilizerRecommendations(): HasMany
    {
        return $this->hasMany(FertilizerRecommendation::class, 'reading_id');
    }

    public function latestFertilizerRecommendation(): HasOne
    {
        return $this->hasOne(FertilizerRecommendation::class, 'reading_id')->latestOfMany();
    }

    public function scopeValid($query)
    {
        return $query->where('is_valid', true);
    }
}
