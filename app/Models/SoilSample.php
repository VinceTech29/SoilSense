<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SoilSample extends Model
{
    use HasFactory;

    protected $table = 'soil_samples';

    protected $fillable = [
        'sample_code',
        'name',
        'location',
        'latitude',
        'longitude',
        'soil_type',
        'collected_at',
        'testing_method',
        'source_type',
        'assessment_status',
        'notes',
        'operator_name',
        'device_id',
        'user_id',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'collected_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(IotDevice::class, 'device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sensorReadings(): HasMany
    {
        return $this->hasMany(SensorReading::class, 'soil_sample_id');
    }

    public function latestReading(): HasOne
    {
        return $this->hasOne(SensorReading::class, 'soil_sample_id')->latestOfMany('recorded_at');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(SoilAssessment::class, 'soil_sample_id');
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(SoilAssessment::class, 'soil_sample_id')->latestOfMany('assessed_at');
    }
}
