<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSoilSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'collected_at' => ['required', 'date'],
            'device_id' => ['nullable', 'exists:iot_devices,id'],
            'testing_method' => ['required', 'in:live_sensor,manual_entry,prototype_sensor'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Sensor Readings (nullable - do not convert missing values to 0)
            'ph' => ['nullable', 'numeric', 'between:0,14'],
            'moisture' => ['nullable', 'numeric', 'between:0,1000'],
            'moisture_unit' => ['nullable', 'string', 'max:30'],
            'temperature' => ['nullable', 'numeric', 'between:-10,60'],
            'ec' => ['nullable', 'numeric', 'min:0'],
            'ec_unit' => ['nullable', 'string', 'max:20'],
            'soil_type' => ['nullable', 'string', 'in:Sandy,Silt,Loamy,Clay'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_demo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A descriptive sample name is required for sample provenance.',
            'ph.between' => 'Soil pH must be physically within the standard 0.00 to 14.00 range.',
            'temperature.between' => 'Soil temperature must be between -10°C and 60°C.',
            'ec.min' => 'Electrical conductivity cannot be a negative value.',
            'soil_type.in' => 'Soil type must be one of: Sandy, Silt, Loamy, Clay.',
            'latitude.between' => 'Latitude must be between -90 and 90 degrees.',
            'longitude.between' => 'Longitude must be between -180 and 180 degrees.',
        ];
    }
}
