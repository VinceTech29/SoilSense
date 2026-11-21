<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FertilizerRecommendationResource extends JsonResource
{
    /**
     * Transform the fertilizer recommendation into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reading = $this->reading;

        return [
            'id' => $this->id,
            'reading_id' => $this->reading_id,
            'crop' => $this->crop,
            'predicted_fertilizer' => $this->predicted_fertilizer,
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'top_predictions' => $this->top_predictions ?? [],
            'dose_kg_ha' => $this->dose_kg_ha !== null ? (float) $this->dose_kg_ha : null,
            'split_schedule' => $this->split_schedule ?? [],
            'rain_advisory' => $this->rain_advisory,
            'model_version' => $this->model_version,
            'fallback_used' => (bool) $this->fallback_used,
            'npk' => [
                'nitrogen' => $reading?->nitrogen !== null ? (float) $reading->nitrogen : null,
                'phosphorus' => $reading?->phosphorus !== null ? (float) $reading->phosphorus : null,
                'potassium' => $reading?->potassium !== null ? (float) $reading->potassium : null,
                'npk_source' => $reading?->npk_source,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
