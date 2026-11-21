<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualNpkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nitrogen' => ['required', 'numeric', 'between:0,500'],
            'phosphorus' => ['required', 'numeric', 'between:0,500'],
            'potassium' => ['required', 'numeric', 'between:0,500'],
            'crop' => ['nullable', 'string', 'max:100'],
            'soil_type' => ['nullable', 'string', 'in:Sandy,Silt,Loamy,Clay'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'nitrogen.required' => 'Nitrogen (N) level in mg/kg is required.',
            'nitrogen.between' => 'Nitrogen (N) level must be between 0 and 500 mg/kg.',
            'phosphorus.required' => 'Phosphorus (P) level in mg/kg is required.',
            'phosphorus.between' => 'Phosphorus (P) level must be between 0 and 500 mg/kg.',
            'potassium.required' => 'Potassium (K) level in mg/kg is required.',
            'potassium.between' => 'Potassium (K) level must be between 0 and 500 mg/kg.',
            'soil_type.in' => 'Soil type must be one of: Sandy, Silt, Loamy, Clay.',
            'latitude.between' => 'Latitude must be between -90 and 90 degrees.',
            'longitude.between' => 'Longitude must be between -180 and 180 degrees.',
        ];
    }
}
