<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IotDevice extends Model
{
    use HasFactory;

    protected $table = 'iot_devices';

    protected $fillable = [
        'device_uid',
        'name',
        'status',
        'api_token_hash',
        'location',
        'is_demo',
        'last_seen_at',
    ];

    protected $hidden = [
        'api_token_hash',
    ];

    protected $casts = [
        'is_demo' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function soilSamples(): HasMany
    {
        return $this->hasMany(SoilSample::class, 'device_id');
    }

    public function sensorReadings(): HasMany
    {
        return $this->hasMany(SensorReading::class, 'device_id');
    }

    public function latestReading(): HasOne
    {
        return $this->hasOne(SensorReading::class, 'device_id')->latestOfMany('recorded_at');
    }

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    public function scopePrototypes($query)
    {
        return $query->where('is_demo', true);
    }
}
