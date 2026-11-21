<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class StoreSensorReadingApiRequest extends FormRequest
{
    /**
     * Determine if the user/device is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'sample_id' => ['required', 'string', 'max:50'],
            'device_id' => ['required', 'string', 'max:50'],
            'ph' => ['nullable', 'numeric', 'between:0.0,14.0'],
            'moisture_percent' => ['nullable', 'numeric', 'between:0.0,100.0'],
            'temperature_c' => ['nullable', 'numeric', 'between:-10.0,60.0'],
            'ec_ms_cm' => ['nullable', 'numeric', 'between:0.0,30.0'],
            'n' => ['nullable', 'numeric', 'between:0.0,500.0'],
            'p' => ['nullable', 'numeric', 'between:0.0,500.0'],
            'k' => ['nullable', 'numeric', 'between:0.0,500.0'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'sample_id.required' => 'The sample_id field is required.',
            'device_id.required' => 'The device_id field is required.',
            'ph.numeric' => 'The soil pH reading must be a valid numeric value.',
            'ph.between' => 'The soil pH reading must be between 0.0 and 14.0.',
            'moisture_percent.numeric' => 'The moisture percentage reading must be a valid numeric value.',
            'moisture_percent.between' => 'The moisture percentage reading must be between 0.0% and 100.0%.',
            'temperature_c.numeric' => 'The temperature reading must be a valid numeric value.',
            'temperature_c.between' => 'The temperature reading must be between -10.0°C and 60.0°C.',
            'ec_ms_cm.numeric' => 'The electrical conductivity (EC) reading must be a valid numeric value.',
            'ec_ms_cm.between' => 'The electrical conductivity (EC) reading must be between 0.0 and 30.0 mS/cm.',
            'n.numeric' => 'The nitrogen (n) reading must be a valid numeric value.',
            'n.between' => 'The nitrogen (n) reading must be between 0.0 and 500.0 mg/kg.',
            'p.numeric' => 'The phosphorus (p) reading must be a valid numeric value.',
            'p.between' => 'The phosphorus (p) reading must be between 0.0 and 500.0 mg/kg.',
            'k.numeric' => 'The potassium (k) reading must be a valid numeric value.',
            'k.between' => 'The potassium (k) reading must be between 0.0 and 500.0 mg/kg.',
            'recorded_at.date' => 'The recorded_at parameter must be a valid ISO 8601 date timestamp.',
        ];
    }

    /**
     * Handle failed validation to return consistent JSON error response.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'error' => 'Validation error: One or more telemetry fields are invalid.',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
                'details' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
