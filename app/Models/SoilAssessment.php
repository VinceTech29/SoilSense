<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoilAssessment extends Model
{
    use HasFactory;

    protected $table = 'soil_assessments';

    protected $fillable = [
        'soil_sample_id',
        'assessed_at',
        'assessment_method',
        'status',
        'usable_parameters_count',
        'total_parameters_count',
        'summary',
        'missing_information',
        'limitations',
        'recommended_next_steps',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'missing_information' => 'array',
        'usable_parameters_count' => 'integer',
        'total_parameters_count' => 'integer',
    ];

    public function soilSample(): BelongsTo
    {
        return $this->belongsTo(SoilSample::class, 'soil_sample_id');
    }

    public function cropEvaluations(): HasMany
    {
        return $this->hasMany(AssessmentCropEvaluation::class, 'soil_assessment_id');
    }
}
