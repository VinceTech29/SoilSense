<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSensorReadingApiRequest;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilSample;
use App\Services\SoilAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SensorReadingApiController extends Controller
{
    public function __construct(
        protected SoilAssessmentService $soilAssessmentService
    ) {}

    /**
     * Ingest telemetry reading from an authenticated ESP32 device.
     */
    public function store(StoreSensorReadingApiRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var IotDevice $authenticatedDevice */
        $authenticatedDevice = $request->attributes->get('authenticated_device');

        // Verify device identity matches the authenticated token
        if ($authenticatedDevice->device_uid !== $validated['device_id']) {
            return response()->json([
                'success' => false,
                'error' => "Device identity mismatch: Token belongs to '{$authenticatedDevice->device_uid}', but payload specified '{$validated['device_id']}'.",
                'code' => 'DEVICE_IDENTITY_MISMATCH',
            ], Response::HTTP_FORBIDDEN);
        }

        // Verify sample exists in the system
        $sample = SoilSample::where('sample_code', $validated['sample_id'])->first();

        if (!$sample) {
            return response()->json([
                'success' => false,
                'error' => "Unknown soil sample: Sample with identifier '{$validated['sample_id']}' was not found.",
                'code' => 'SAMPLE_NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        // Safe extraction of optional parameters
        $ph = $validated['ph'] ?? null;
        $moisturePercent = $validated['moisture_percent'] ?? null;
        $temperatureC = $validated['temperature_c'] ?? null;
        $ecMsCm = $validated['ec_ms_cm'] ?? null;

        // Idempotency / Duplicate protection: Check if identical reading was submitted recently (< 10 seconds)
        $recentDuplicate = SensorReading::where('soil_sample_id', $sample->id)
            ->where('device_id', $authenticatedDevice->id)
            ->where('created_at', '>=', Carbon::now()->subSeconds(10))
            ->where('ph', $ph)
            ->where('moisture', $moisturePercent)
            ->where('temperature', $temperatureC)
            ->where('ec', $ecMsCm)
            ->first();

        if ($recentDuplicate) {
            return response()->json([
                'success' => false,
                'error' => 'Duplicate submission detected: Identical sensor telemetry was received within the last 10 seconds.',
                'code' => 'DUPLICATE_SUBMISSION',
                'reading_id' => $recentDuplicate->id,
            ], Response::HTTP_CONFLICT);
        }

        // Determine calibration metadata and units
        $hasMoisture = $moisturePercent !== null;
        $moistureUnit = $hasMoisture ? '% VWC' : 'raw index';
        $calibrationMetadata = [
            'moisture_calibrated' => $hasMoisture,
            'moisture_unit' => $moistureUnit,
            'ec_unit' => 'mS/cm',
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent() ?? 'ESP32-HTTP-Client/1.0',
        ];

        // Parse recorded timestamp or default to now
        $recordedAt = isset($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'])
            : Carbon::now();

        // Transactional persistence & assessment evaluation
        $result = DB::transaction(function () use ($sample, $authenticatedDevice, $ph, $moisturePercent, $temperatureC, $ecMsCm, $moistureUnit, $calibrationMetadata, $recordedAt) {
            // Update device status and communication metadata
            $authenticatedDevice->update([
                'last_seen_at' => Carbon::now(),
                'status' => 'online',
            ]);

            // Create sensor reading record
            $reading = SensorReading::create([
                'soil_sample_id' => $sample->id,
                'device_id' => $authenticatedDevice->id,
                'ph' => $ph,
                'moisture' => $moisturePercent,
                'moisture_unit' => $moistureUnit,
                'temperature' => $temperatureC,
                'ec' => $ecMsCm,
                'ec_unit' => 'mS/cm',
                'is_valid' => true,
                'validity_notes' => 'Ingested via authenticated ESP32 REST API',
                'calibration_metadata' => $calibrationMetadata,
                'recorded_at' => $recordedAt,
                'data_source' => $authenticatedDevice->is_demo ? 'demo' : 'live',
            ]);

            // Audit reading to determine if sufficient usable parameters exist
            $audit = $this->soilAssessmentService->auditReading($reading);
            $assessmentTriggered = false;
            $assessment = null;

            // Trigger assessment if sufficient data is available (usable >= 2)
            if ($audit['usable_count'] >= 2) {
                $assessment = $this->soilAssessmentService->evaluateSample($sample, $reading);
                $assessmentTriggered = true;
            }

            return [
                'reading' => $reading,
                'audit' => $audit,
                'assessment_triggered' => $assessmentTriggered,
                'assessment' => $assessment,
            ];
        });

        $reading = $result['reading'];
        $audit = $result['audit'];
        $assessmentTriggered = $result['assessment_triggered'];
        $assessment = $result['assessment'];

        return response()->json([
            'success' => true,
            'message' => 'Sensor reading ingested and registered successfully.',
            'data' => [
                'reading_id' => $reading->id,
                'sample_id' => $sample->sample_code,
                'device_id' => $authenticatedDevice->device_uid,
                'device_status' => $authenticatedDevice->status,
                'recorded_at' => $reading->recorded_at->toIso8601String(),
                'measurements' => [
                    'ph' => [
                        'value' => $reading->ph,
                        'unit' => null,
                        'valid' => $audit['is_ph_valid'],
                    ],
                    'moisture' => [
                        'value' => $reading->moisture,
                        'unit' => $reading->moisture_unit,
                        'calibrated' => $audit['is_moisture_calibrated'],
                    ],
                    'temperature' => [
                        'value' => $reading->temperature,
                        'unit' => '°C',
                        'valid' => $audit['is_temp_valid'],
                    ],
                    'ec' => [
                        'value' => $reading->ec,
                        'unit' => $reading->ec_unit,
                        'valid' => $audit['is_ec_valid'],
                    ],
                ],
                'data_quality' => [
                    'usable_parameters_count' => $audit['usable_count'],
                    'total_parameters' => $audit['total_count'],
                    'missing_parameters' => $audit['missing_parameters'],
                    'quality_notes' => $audit['quality_notes'],
                ],
                'assessment' => [
                    'triggered' => $assessmentTriggered,
                    'status' => $assessment?->status ?? 'pending_sufficient_telemetry',
                    'summary' => $assessment?->summary,
                ],
            ],
        ], Response::HTTP_CREATED);
    }
}
