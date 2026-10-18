<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentCropEvaluation extends Model
{
    use HasFactory;

    protected $table = 'assessment_crop_evaluations';

    protected $fillable = [
        'soil_assessment_id',
        'crop_profile_id',
        'suitability_tier',
        'tone',
        'match_score',
        'supporting_factors',
        'limiting_factors',
        'ph_status',
        'moisture_status',
        'temperature_status',
        'ec_status',
        'recommendation_notes',
    ];

    protected $casts = [
        'match_score' => 'float',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(SoilAssessment::class, 'soil_assessment_id');
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(CropProfile::class, 'crop_profile_id');
    }

    /**
     * Get structured JSON details stored in recommendation_notes.
     */
    public function getStructuredDetailsAttribute(): array
    {
        if (empty($this->recommendation_notes)) {
            return [];
        }

        $decoded = json_decode($this->recommendation_notes, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get supporting factors as an array of items.
     */
    public function getSupportingFactorsListAttribute(): array
    {
        if (empty($this->supporting_factors)) {
            return [];
        }

        return array_filter(array_map('trim', explode(';', $this->supporting_factors)));
    }

    /**
     * Get limiting factors as an array of items.
     */
    public function getLimitingFactorsListAttribute(): array
    {
        if (empty($this->limiting_factors)) {
            return [];
        }

        return array_filter(array_map('trim', explode(';', $this->limiting_factors)));
    }

    /**
     * Get confidence label from structured details.
     */
    public function getConfidenceLabelAttribute(): string
    {
        return $this->structured_details['confidence_label'] ?? 'Preliminary Evidence';
    }

    /**
     * Get missing measurements from structured details.
     */
    public function getMissingMeasurementsAttribute(): array
    {
        return $this->structured_details['missing_measurements'] ?? [];
    }

    /**
     * Get recommended follow up tests from structured details.
     */
    public function getRecommendedFollowUpTestsAttribute(): array
    {
        return $this->structured_details['recommended_follow_up_tests'] ?? [];
    }
}
