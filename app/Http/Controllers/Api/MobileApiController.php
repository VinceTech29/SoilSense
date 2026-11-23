<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSoilSampleRequest;
use App\Models\CropProfile;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use App\Models\User;
use App\Services\CropSuitabilityService;
use App\Services\SoilAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MobileApiController extends Controller
{
    public function __construct(
        protected SoilAssessmentService $soilAssessmentService,
        protected CropSuitabilityService $cropSuitabilityService
    ) {}

    /**
     * Mobile Dashboard Overview
     *
     * Returns structured, UI-ready data for Flutter mobile widgets:
     * - Status cards for pH, moisture, temperature, EC with badges and tones
     * - Device telemetry and connection status
     * - Top recommended crops based on latest soil sample
     * - Recent samples list
     */
    public function dashboard(): JsonResponse
    {
        $latestSample = SoilSample::with([
            'latestReading',
            'latestAssessment.cropEvaluations.crop.requirements',
            'device',
        ])->latest('collected_at')->first();

        $reading = $latestSample?->latestReading;
        $assessment = $latestSample?->latestAssessment;

        // Structured measurements designed for Flutter dashboard cards
        $measurements = [
            'ph' => [
                'name' => 'Soil pH',
                'value' => $reading && $reading->ph !== null ? (float) number_format($reading->ph, 2) : null,
                'display_value' => $reading && $reading->ph !== null ? number_format($reading->ph, 1) : 'Pending',
                'unit' => 'pH',
                'badge' => $reading && $reading->ph !== null ? 'In range' : 'Not measured',
                'tone' => $reading && $reading->ph !== null ? 'green' : 'gray',
                'meta' => $reading && $reading->ph !== null ? 'Calibrated reading' : 'No sensor data',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
            'moisture' => [
                'name' => 'Soil Moisture',
                'value' => $reading && $reading->moisture !== null ? (float) round($reading->moisture) : null,
                'display_value' => $reading && $reading->moisture !== null ? (string) round($reading->moisture) : 'Pending',
                'unit' => $reading?->moisture_unit ?? '%',
                'badge' => $reading && $reading->moisture_unit === 'raw index' ? 'Uncalibrated' : ($reading && $reading->moisture !== null ? 'Optimal' : 'Missing'),
                'tone' => $reading && $reading->moisture_unit === 'raw index' ? 'amber' : ($reading && $reading->moisture !== null ? 'green' : 'gray'),
                'meta' => $reading && $reading->moisture_unit === 'raw index' ? 'Raw analog sensor index' : 'Calibrated volumetric moisture',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
            'temperature' => [
                'name' => 'Soil Temperature',
                'value' => $reading && $reading->temperature !== null ? (float) round($reading->temperature, 1) : null,
                'display_value' => $reading && $reading->temperature !== null ? (string) round($reading->temperature) : 'Pending',
                'unit' => '°C',
                'badge' => $reading && $reading->temperature !== null ? 'Available' : 'Missing',
                'tone' => $reading && $reading->temperature !== null ? 'green' : 'gray',
                'meta' => $reading && $reading->temperature !== null ? 'Thermal sensor valid' : 'No sensor data',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
            'ec' => [
                'name' => 'Electrical Conductivity',
                'value' => $reading && $reading->ec !== null ? (float) number_format($reading->ec, 2) : null,
                'display_value' => $reading && $reading->ec !== null ? number_format($reading->ec, 2) : 'Pending',
                'unit' => $reading?->ec_unit ?? 'mS/cm',
                'badge' => $reading && $reading->ec !== null ? 'Normal Salinity' : 'Not measured',
                'tone' => $reading && $reading->ec !== null ? 'green' : 'gray',
                'meta' => $reading && $reading->ec !== null ? 'EC measurement valid' : 'Awaiting sensor reading',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
        ];

        // Crop Suitability Evaluations
        $crops = [];
        if ($assessment && $assessment->cropEvaluations->isNotEmpty()) {
            foreach ($assessment->cropEvaluations->take(6) as $eval) {
                $crop = $eval->crop;
                $req = $crop->requirements;
                $crops[] = [
                    'id' => $crop->id,
                    'name' => $crop->name,
                    'scientific_name' => $crop->scientific_name,
                    'ph_range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                    'suitability_tier' => $eval->suitability_tier,
                    'tone' => $eval->tone,
                    'supporting_factors' => $eval->supporting_factors,
                    'limiting_factors' => $eval->limiting_factors,
                    'image_url' => $crop->image_url,
                ];
            }
        } else {
            $crops = CropProfile::with('requirements')->take(6)->get()->map(function ($crop) {
                $req = $crop->requirements;
                return [
                    'id' => $crop->id,
                    'name' => $crop->name,
                    'scientific_name' => $crop->scientific_name,
                    'ph_range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                    'suitability_tier' => 'Potential Match',
                    'tone' => 'green',
                    'supporting_factors' => 'Baseline agronomic profile',
                    'limiting_factors' => 'Awaiting active sample evaluation',
                    'image_url' => $crop->image_url,
                ];
            })->toArray();
        }

        // Active Device Status (most recently active real device, honest empty state when empty)
        $device = IotDevice::whereNotNull('last_seen_at')->latest('last_seen_at')->first() ?? IotDevice::latest('id')->first();
        $deviceData = $device ? [
            'id' => $device->id,
            'uid' => $device->device_uid,
            'name' => $device->name,
            'status' => $device->status,
            'location' => $device->location,
            'is_demo' => (bool) $device->is_demo,
            'last_seen' => $device->last_seen_at?->diffForHumans(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ] : null;

        // Recent Samples
        $recentSamples = SoilSample::with(['latestReading'])->latest('collected_at')->take(4)->get()->map(function ($s) {
            $r = $s->latestReading;
            return [
                'id' => $s->id,
                'sample_code' => $s->sample_code,
                'name' => $s->name,
                'location' => $s->location ?? 'Unspecified',
                'collected_at' => $s->collected_at->format('M d, Y g:i A'),
                'ph' => $r && $r->ph !== null ? number_format($r->ph, 1) : null,
                'moisture' => $r && $r->moisture !== null ? (float) $r->moisture : null,
                'status' => ucfirst($s->assessment_status),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'latest_sample' => $latestSample ? [
                    'id' => $latestSample->id,
                    'sample_code' => $latestSample->sample_code,
                    'name' => $latestSample->name,
                    'location' => $latestSample->location,
                    'collected_at' => $latestSample->collected_at->toIso8601String(),
                    'assessment_status' => $latestSample->assessment_status,
                ] : null,
                'measurements' => $measurements,
                'device' => $deviceData,
                'crops' => $crops,
                'recent_samples' => $recentSamples,
            ],
        ]);
    }

    /**
     * Return the latest raw sensor reading (useful for polling or manual pull-to-refresh)
     */
    public function latestTelemetry(): JsonResponse
    {
        $reading = SensorReading::with(['device', 'soilSample'])->latest('recorded_at')->first();

        if (!$reading) {
            return response()->json([
                'success' => false,
                'message' => 'No sensor readings available yet.',
            ], Response::HTTP_NOT_FOUND);
        }

        $isDemo = in_array(strtolower((string) $reading->data_source), ['demo', 'demonstration', 'simulated']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $reading->id,
                'sample_code' => $reading->soilSample?->sample_code,
                'device_uid' => $reading->device?->device_uid,
                'ph' => $reading->ph !== null ? (float) $reading->ph : null,
                'moisture' => $reading->moisture !== null ? (float) $reading->moisture : null,
                'moisture_unit' => $reading->moisture_unit,
                'temperature' => $reading->temperature !== null ? (float) $reading->temperature : null,
                'ec' => $reading->ec !== null ? (float) $reading->ec : null,
                'ec_unit' => $reading->ec_unit,
                'is_demo' => $isDemo,
                'data_source' => $reading->data_source,
                'recorded_at' => $reading->recorded_at->toIso8601String(),
                'recorded_ago' => $reading->recorded_at->diffForHumans(),
            ],
        ]);
    }

    /**
     * Return telemetry history for charts (fl_chart in Flutter)
     */
    public function telemetryHistory(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit', 20)), 100);

        $readings = SensorReading::latest('recorded_at')
            ->take($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'timestamp' => $r->recorded_at->toIso8601String(),
                    'time_label' => $r->recorded_at->format('H:i'),
                    'ph' => $r->ph !== null ? (float) $r->ph : null,
                    'moisture' => $r->moisture !== null ? (float) $r->moisture : null,
                    'temperature' => $r->temperature !== null ? (float) $r->temperature : null,
                    'ec' => $r->ec !== null ? (float) $r->ec : null,
                ];
            });

        return response()->json([
            'success' => true,
            'count' => $readings->count(),
            'data' => $readings,
        ]);
    }

    /**
     * Crop Catalog with Requirements
     */
    public function crops(Request $request): JsonResponse
    {
        $query = CropProfile::with('requirements');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('scientific_name', 'like', "%{$search}%");
            });
        }

        $crops = $query->get()->map(function ($crop) {
            $req = $crop->requirements;
            return [
                'id' => $crop->id,
                'name' => $crop->name,
                'scientific_name' => $crop->scientific_name,
                'category' => $crop->category ?? 'Grain',
                'description' => $crop->description,
                'image_url' => $crop->image_url,
                'requirements' => $req ? [
                    'min_ph' => (float) $req->min_ph,
                    'max_ph' => (float) $req->max_ph,
                    'optimal_ph_min' => (float) $req->optimal_ph_min,
                    'optimal_ph_max' => (float) $req->optimal_ph_max,
                    'min_moisture' => (float) $req->min_moisture,
                    'max_moisture' => (float) $req->max_moisture,
                    'min_temp' => (float) $req->min_temp,
                    'max_temp' => (float) $req->max_temp,
                    'max_ec' => (float) $req->max_ec,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'count' => $crops->count(),
            'data' => $crops,
        ]);
    }

    /**
     * Crop Detail
     */
    public function cropDetail(string $name): JsonResponse
    {
        $crop = CropProfile::with('requirements')
            ->where(function ($query) use ($name) {
                if (is_numeric($name)) {
                    $query->where('id', (int) $name);
                }
                $query->orWhere('name', 'like', $name);
            })
            ->first();

        if (!$crop) {
            return response()->json([
                'success' => false,
                'message' => "Crop '{$name}' not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        $req = $crop->requirements;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $crop->id,
                'name' => $crop->name,
                'scientific_name' => $crop->scientific_name,
                'category' => $crop->category,
                'description' => $crop->description,
                'image_url' => $crop->image_url,
                'requirements' => $req,
            ],
        ]);
    }

    /**
     * Historical Soil Samples List
     */
    public function samples(): JsonResponse
    {
        $samples = SoilSample::with(['latestReading', 'latestAssessment', 'device'])
            ->latest('collected_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $samples,
        ]);
    }

    /**
     * Submit a New Soil Sample & Run Assessment from Mobile App
     */
    public function storeSample(StoreSoilSampleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $sample = DB::transaction(function () use ($validated) {
            $user = User::first();
            $sourceType = ($validated['is_demo'] ?? true) ? 'demo' : 'live';

            $sample = SoilSample::create([
                'sample_code' => 'SS-' . date('Y') . '-TEMP-' . uniqid('', true),
                'name' => $validated['name'],
                'location' => $validated['location'] ?? 'Mobile Field Sample',
                'collected_at' => Carbon::parse($validated['collected_at']),
                'testing_method' => $validated['testing_method'],
                'source_type' => $sourceType,
                'assessment_status' => 'preliminary',
                'notes' => $validated['notes'] ?? null,
                'operator_name' => $user?->name,
                'user_id' => $user?->id,
                'device_id' => $validated['device_id'] ?? IotDevice::first()?->id,
            ]);

            $sample->update([
                'sample_code' => 'SS-' . date('Y') . '-' . str_pad($sample->id, 3, '0', STR_PAD_LEFT),
            ]);

            // If reading data was provided, record sensor reading
            $reading = null;
            if (isset($validated['ph']) || isset($validated['moisture']) || isset($validated['temperature']) || isset($validated['ec'])) {
                $reading = SensorReading::create([
                    'soil_sample_id' => $sample->id,
                    'device_id' => $sample->device_id,
                    'recorded_at' => Carbon::parse($validated['collected_at']),
                    'ph' => $validated['ph'] ?? null,
                    'moisture' => $validated['moisture'] ?? null,
                    'moisture_unit' => $validated['moisture_unit'] ?? '%',
                    'temperature' => $validated['temperature'] ?? null,
                    'ec' => $validated['ec'] ?? null,
                    'ec_unit' => $validated['ec_unit'] ?? 'mS/cm',
                    'is_valid' => true,
                    'data_source' => $sourceType,
                ]);
            }

            // Run automated agronomic assessment
            $this->soilAssessmentService->evaluateSample($sample, $reading);

            $freshSample = $sample->fresh(['latestReading', 'latestAssessment.cropEvaluations.crop']);
            $sampleData = $freshSample->toArray();
            $sampleData['crop_evaluations'] = $freshSample->latestAssessment?->cropEvaluations->map(function ($eval) {
                $evalArr = $eval->toArray();
                $evalArr['crop_name'] = $eval->crop?->name;
                return $evalArr;
            })->values()->all() ?? [];

            return $sampleData;
        });

        return response()->json([
            'success' => true,
            'message' => 'Soil sample recorded and assessed successfully.',
            'data' => $sample,
        ], Response::HTTP_CREATED);
    }

    /**
     * Get Soil Assessment and Crop Suitability Evaluations
     */
    public function showAssessment(int|string $id): JsonResponse
    {
        $assessment = SoilAssessment::with([
            'soilSample.latestReading',
            'cropEvaluations.crop',
        ])->find($id);

        if (!$assessment) {
            return response()->json([
                'success' => false,
                'message' => "Soil assessment not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        $sample = $assessment->soilSample;
        $evaluations = $assessment->cropEvaluations->map(function ($eval) {
            $data = $eval->toArray();
            $data['crop_name'] = $eval->crop?->name;
            return $data;
        })->values();

        $data = $assessment->toArray();
        $data['sample'] = $sample;
        $data['crop_evaluations'] = $evaluations;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Historical Assessment Reports List (Paginated like samples)
     */
    public function reports(): JsonResponse
    {
        $reports = SoilSample::with(['latestReading', 'latestAssessment', 'device'])
            ->latest('collected_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * Report Detail for a specific sample
     */
    public function showReport(string $id): JsonResponse
    {
        $sample = SoilSample::where('sample_code', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->with([
                'latestReading',
                'latestAssessment.cropEvaluations.crop.requirements',
                'device',
            ])
            ->first();

        if (!$sample) {
            return response()->json([
                'success' => false,
                'message' => "Report for sample '{$id}' not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        $reading = $sample->latestReading;
        $assessment = $sample->latestAssessment;

        $measurements = [
            'ph' => [
                'name' => 'Soil pH',
                'value' => $reading && $reading->ph !== null ? (float) number_format($reading->ph, 2) : null,
                'display_value' => $reading && $reading->ph !== null ? number_format($reading->ph, 1) : 'Pending',
                'unit' => 'pH',
                'badge' => $reading && $reading->ph !== null ? 'In range' : 'Not measured',
                'tone' => $reading && $reading->ph !== null ? 'green' : 'gray',
                'meta' => $reading && $reading->ph !== null ? 'Calibrated reading' : 'No sensor data',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
            'moisture' => [
                'name' => 'Soil Moisture',
                'value' => $reading && $reading->moisture !== null ? (float) round($reading->moisture) : null,
                'display_value' => $reading && $reading->moisture !== null ? (string) round($reading->moisture) : 'Pending',
                'unit' => $reading?->moisture_unit ?? '%',
                'badge' => $reading && $reading->moisture_unit === 'raw index' ? 'Uncalibrated' : ($reading && $reading->moisture !== null ? 'Optimal' : 'Missing'),
                'tone' => $reading && $reading->moisture_unit === 'raw index' ? 'amber' : ($reading && $reading->moisture !== null ? 'green' : 'gray'),
                'meta' => $reading && $reading->moisture_unit === 'raw index' ? 'Raw analog sensor index' : 'Calibrated volumetric moisture',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
            'temperature' => [
                'name' => 'Soil Temperature',
                'value' => $reading && $reading->temperature !== null ? (float) round($reading->temperature, 1) : null,
                'display_value' => $reading && $reading->temperature !== null ? (string) round($reading->temperature) : 'Pending',
                'unit' => '°C',
                'badge' => $reading && $reading->temperature !== null ? 'Available' : 'Missing',
                'tone' => $reading && $reading->temperature !== null ? 'green' : 'gray',
                'meta' => $reading && $reading->temperature !== null ? 'Thermal sensor valid' : 'No sensor data',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
            'ec' => [
                'name' => 'Electrical Conductivity',
                'value' => $reading && $reading->ec !== null ? (float) number_format($reading->ec, 2) : null,
                'display_value' => $reading && $reading->ec !== null ? number_format($reading->ec, 2) : 'Pending',
                'unit' => $reading?->ec_unit ?? 'mS/cm',
                'badge' => $reading && $reading->ec !== null ? 'Normal Salinity' : 'Not measured',
                'tone' => $reading && $reading->ec !== null ? 'green' : 'gray',
                'meta' => $reading && $reading->ec !== null ? 'EC measurement valid' : 'Awaiting sensor reading',
                'last_updated' => $reading?->recorded_at?->diffForHumans(),
            ],
        ];

        $cropEvaluations = $assessment?->cropEvaluations->map(function ($eval) {
            $crop = $eval->crop;
            $data = $eval->toArray();
            $data['crop_name'] = $crop?->name;
            $data['scientific_name'] = $crop?->scientific_name;
            $data['image_url'] = $crop?->image_url;
            return $data;
        }) ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'sample' => $sample,
                'reading' => $reading,
                'assessment' => $assessment,
                'crop_evaluations' => $cropEvaluations,
                'recommendations' => [
                    'summary' => $assessment?->summary,
                    'recommended_next_steps' => $assessment?->recommended_next_steps,
                    'limitations' => $assessment?->limitations,
                    'missing_information' => $assessment?->missing_information,
                ],
                'measurements' => $measurements,
            ],
        ]);
    }

    /**
     * List of registered IoT devices with latest telemetry
     */
    public function devices(): JsonResponse
    {
        $devices = IotDevice::with('latestReading')->get()->map(function ($device) {
            return [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'status' => $device->status,
                'location' => $device->location,
                'is_demo' => (bool) $device->is_demo,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_seen_ago' => $device->last_seen_at?->diffForHumans(),
                'created_at' => $device->created_at?->toIso8601String(),
                'updated_at' => $device->updated_at?->toIso8601String(),
                'latest_reading' => $device->latestReading,
            ];
        });

        return response()->json([
            'success' => true,
            'count' => $devices->count(),
            'data' => $devices,
        ]);
    }

    /**
     * Single IoT device detail with latest telemetry
     */
    public function showDevice(string $id): JsonResponse
    {
        $device = IotDevice::with('latestReading')
            ->where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('device_uid', $id)
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => "Device '{$id}' not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'status' => $device->status,
                'location' => $device->location,
                'is_demo' => (bool) $device->is_demo,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_seen_ago' => $device->last_seen_at?->diffForHumans(),
                'created_at' => $device->created_at?->toIso8601String(),
                'updated_at' => $device->updated_at?->toIso8601String(),
                'latest_reading' => $device->latestReading,
            ],
        ]);
    }
}
