<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSoilSampleRequest;
use App\Models\CropProfile;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use App\Models\User;
use App\Services\SoilAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SoilSenseController extends Controller
{
    /**
     * Public Landing Page
     */
    public function landing()
    {
        $crops = CropProfile::with('requirements')->take(3)->get()->map(function ($crop) {
            $req = $crop->requirements;
            return [
                'name' => $crop->name,
                'scientific' => $crop->scientific_name,
                'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'pH range pending',
                'status' => 'Potential Match',
                'tone' => 'green',
                'support' => 'pH and temperature align',
                'limit' => 'EC, texture, nutrients missing',
                'image' => $crop->image_url,
            ];
        });

        return view('landing', compact('crops'));
    }

    /**
     * Main Overview Dashboard connected to real database queries
     */
    public function dashboard()
    {
        $latestSample = SoilSample::with([
            'latestReading',
            'latestAssessment.cropEvaluations.crop.requirements',
            'device',
        ])->latest('collected_at')->first();

        $reading = $latestSample?->latestReading;
        $assessment = $latestSample?->latestAssessment;

        // Construct measurements array from real database record
        $measurements = [
            [
                'name' => 'Soil pH',
                'value' => $reading && $reading->ph !== null ? number_format($reading->ph, 1) : 'Pending',
                'unit' => null,
                'meta' => $reading && $reading->ph !== null ? 'Within sample crop ranges' : 'No sensor data',
                'icon' => 'flask',
                'badge' => $reading && $reading->ph !== null ? 'In range' : 'Not measured',
                'tone' => $reading && $reading->ph !== null ? 'green' : 'gray',
                'note' => $reading ? 'Updated ' . $reading->recorded_at->diffForHumans() : 'Awaiting sensor',
            ],
            [
                'name' => 'Soil moisture',
                'value' => $reading && $reading->moisture !== null ? (string) round($reading->moisture) : 'Pending',
                'unit' => $reading?->moisture_unit ?? 'raw index',
                'meta' => $reading && $reading->moisture_unit === 'raw index' ? 'Sensor is not % calibrated' : 'Calibrated reading',
                'icon' => 'droplet',
                'badge' => $reading && $reading->moisture_unit === 'raw index' ? 'Uncalibrated' : ($reading && $reading->moisture !== null ? 'In range' : 'Missing'),
                'tone' => $reading && $reading->moisture_unit === 'raw index' ? 'amber' : ($reading && $reading->moisture !== null ? 'green' : 'gray'),
                'note' => $reading ? 'Updated ' . $reading->recorded_at->diffForHumans() : 'Awaiting sensor',
            ],
            [
                'name' => 'Soil temperature',
                'value' => $reading && $reading->temperature !== null ? (string) round($reading->temperature) : 'Pending',
                'unit' => '°C',
                'meta' => $reading && $reading->temperature !== null ? 'Valid sensor reading' : 'No sensor data',
                'icon' => 'temp',
                'badge' => $reading && $reading->temperature !== null ? 'Available' : 'Missing',
                'tone' => $reading && $reading->temperature !== null ? 'green' : 'gray',
                'note' => $reading ? 'Updated ' . $reading->recorded_at->diffForHumans() : 'Awaiting sensor',
            ],
        ];

        // Format crops from real database evaluations
        $crops = [];
        if ($assessment && $assessment->cropEvaluations->isNotEmpty()) {
            foreach ($assessment->cropEvaluations as $eval) {
                $crop = $eval->crop;
                $req = $crop->requirements;
                $crops[] = [
                    'name' => $crop->name,
                    'scientific' => $crop->scientific_name,
                    'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                    'status' => $eval->suitability_tier,
                    'tone' => $eval->tone,
                    'support' => $eval->supporting_factors,
                    'limit' => $eval->limiting_factors,
                    'image' => $crop->image_url,
                ];
            }
        } else {
            $crops = CropProfile::with('requirements')->take(6)->get()->map(function ($crop) {
                $req = $crop->requirements;
                return [
                    'name' => $crop->name,
                    'scientific' => $crop->scientific_name,
                    'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                    'status' => 'Potential Match',
                    'tone' => 'green',
                    'support' => 'pH and temperature align',
                    'limit' => 'Awaiting active sample evaluation',
                    'image' => $crop->image_url,
                ];
            })->toArray();
        }

        // Recent samples list from database
        $samples = SoilSample::with(['latestReading', 'device'])->latest('collected_at')->take(4)->get()->map(function ($s) {
            $r = $s->latestReading;
            return [
                'id' => $s->sample_code,
                'name' => $s->name,
                'date' => $s->collected_at->format('M d, g:i A'),
                'location' => $s->location ?? 'Unspecified',
                'ph' => $r && $r->ph !== null ? number_format($r->ph, 1) : '—',
                'moisture' => $r && $r->moisture !== null ? "{$r->moisture} {$r->moisture_unit}" : '—',
                'temperature' => $r && $r->temperature !== null ? "{$r->temperature}°C" : '—',
                'ec' => $r && $r->ec !== null ? "{$r->ec} {$r->ec_unit}" : 'Pending',
                'assessment' => ucfirst($s->assessment_status),
            ];
        })->toArray();

        // Device telemetry
        $device = IotDevice::where('device_uid', 'ESP32-01')->first() ?? IotDevice::first();

        return view('dashboard', [
            'title' => 'Overview',
            'latestSample' => $latestSample,
            'reading' => $reading,
            'assessment' => $assessment,
            'device' => $device,
            'crops' => $crops,
            'measurements' => $measurements,
            'samples' => $samples,
        ]);
    }

    /**
     * Soil Analysis page & New Test form
     */
    public function analysis(Request $request)
    {
        // When clicking "+ New Soil Test", silently advance to the next soil sample in sequence!
        if ($request->has('advance') || $request->has('new')) {
            self::advanceActiveSample();
        }

        $devices = IotDevice::all();
        $latestReading = SensorReading::latest('recorded_at')->first();
        $activeSample = self::getActiveSampleData();

        return view('analysis', [
            'title' => 'Soil Analysis',
            'devices' => $devices,
            'latestReading' => $latestReading,
            'activeSample' => $activeSample,
        ]);
    }

    /**
     * Store new soil sample, record reading, and perform assessment transaction
     */
    public function storeSample(StoreSoilSampleRequest $request, SoilAssessmentService $assessmentService)
    {
        $validated = $request->validated();

        $sample = DB::transaction(function () use ($validated, $assessmentService) {
            $count = SoilSample::count() + 1;
            $sampleCode = 'SS-' . date('Y') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

            $user = User::first();

            $sample = SoilSample::create([
                'sample_code' => $sampleCode,
                'name' => $validated['name'],
                'location' => $validated['location'] ?? 'Demo Farm, Laguna',
                'collected_at' => Carbon::parse($validated['collected_at']),
                'testing_method' => $validated['testing_method'],
                'source_type' => $validated['is_demo'] ?? true ? 'demo' : 'live',
                'assessment_status' => 'preliminary',
                'notes' => $validated['notes'] ?? null,
                'operator_name' => $user?->name ?? 'Juan Dela Cruz',
                'device_id' => $validated['device_id'] ?? IotDevice::first()?->id,
                'user_id' => $user?->id,
            ]);

            $reading = SensorReading::create([
                'soil_sample_id' => $sample->id,
                'device_id' => $sample->device_id,
                'ph' => $validated['ph'] ?? null,
                'moisture' => $validated['moisture'] ?? null,
                'moisture_unit' => $validated['moisture_unit'] ?? 'raw index',
                'temperature' => $validated['temperature'] ?? null,
                'ec' => $validated['ec'] ?? null,
                'ec_unit' => $validated['ec_unit'] ?? 'mS/cm',
                'is_valid' => true,
                'recorded_at' => Carbon::now(),
                'data_source' => $sample->source_type,
            ]);

            $assessmentService->evaluateSample($sample, $reading);

            return $sample;
        });

        return redirect()->route('report', ['id' => $sample->sample_code])
            ->with('success', "Soil sample {$sample->sample_code} registered and assessed successfully!");
    }

    /**
     * Crop Recommendations catalog
     */
    public function crops(Request $request)
    {
        $latestSample = SoilSample::with(['latestAssessment.cropEvaluations'])->latest('collected_at')->first();
        $query = CropProfile::with('requirements');

        if ($request->filled('category') && $request->category !== 'All') {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('scientific_name', 'like', "%{$search}%");
            });
        }

        $crops = $query->get()->map(function ($crop) use ($latestSample) {
            $req = $crop->requirements;
            $eval = $latestSample?->latestAssessment?->cropEvaluations->firstWhere('crop_profile_id', $crop->id);

            return [
                'name' => $crop->name,
                'scientific' => $crop->scientific_name,
                'category' => $crop->category,
                'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                'status' => $eval?->suitability_tier ?? 'Potential Match',
                'tone' => $eval?->tone ?? 'green',
                'support' => $eval?->supporting_factors ?? 'pH and temperature align with documented requirements',
                'limit' => $eval?->limiting_factors ?? 'Moisture is uncalibrated; EC and nutrients unassessed',
                'image' => $crop->image_url,
                'score' => $eval?->match_score,
            ];
        })->toArray();

        return view('crops', [
            'title' => 'Crop Recommendations',
            'crops' => $crops,
            'latestSample' => $latestSample,
        ]);
    }

    /**
     * Individual Crop Profile Details
     */
    public function cropDetail($name = 'tomato')
    {
        $cleanName = trim(str_replace('-', ' ', $name));
        $crop = CropProfile::where('name', $name)
            ->orWhere('name', $cleanName)
            ->orWhere('name', ucwords(strtolower($cleanName)))
            ->orWhere('name', ucfirst(strtolower($name)))
            ->orWhereRaw("LOWER(REPLACE(name, ' ', '-')) = ?", [strtolower($name)])
            ->orWhere('id', is_numeric($name) ? (int) $name : 0)
            ->with('requirements')
            ->firstOrFail();

        $latestSample = SoilSample::with(['latestReading', 'latestAssessment.cropEvaluations'])->latest('collected_at')->first();
        $reading = $latestSample?->latestReading;
        $eval = $latestSample?->latestAssessment?->cropEvaluations->firstWhere('crop_profile_id', $crop->id);

        $cropData = [
            'name' => $crop->name,
            'scientific' => $crop->scientific_name,
            'range' => $crop->requirements ? "pH {$crop->requirements->min_ph}–{$crop->requirements->max_ph}" : 'Pending',
            'status' => $eval?->suitability_tier ?? 'Potential Match',
            'tone' => $eval?->tone ?? 'green',
            'support' => $eval?->supporting_factors ?? 'pH and temperature align with referenced ranges',
            'limit' => $eval?->limiting_factors ?? 'Moisture is uncalibrated; EC and nutrients unassessed',
            'image' => $crop->image_url,
            'description' => $crop->description,
            'requirements' => $crop->requirements,
            'reading' => $reading,
            'score' => $eval?->match_score,
            'confidence' => $eval?->confidence_label ?? 'Preliminary Evidence (2/4 factors)',
            'missing_measurements' => $eval?->missing_measurements ?? [],
            'next_steps' => !empty($eval?->recommended_follow_up_tests) ? implode(' ', $eval->recommended_follow_up_tests) : null,
            'eval' => $eval,
        ];

        return view('crop-detail', [
            'title' => 'Crop Details',
            'crop' => $cropData,
        ]);
    }

    /**
     * Soil Assessment Report page
     */
    public function report($id = null)
    {
        $sample = null;
        if ($id) {
            $sample = SoilSample::where('sample_code', $id)->first();
        }

        if (!$sample) {
            $sample = SoilSample::latest('collected_at')->firstOrFail();
        }

        $sample->load([
            'latestReading',
            'latestAssessment.cropEvaluations.crop.requirements',
            'device',
        ]);

        $reading = $sample->latestReading;
        $assessment = $sample->latestAssessment;

        // Structured measurements from actual database
        $measurements = [
            [
                'name' => 'Soil pH',
                'value' => $reading && $reading->ph !== null ? number_format($reading->ph, 1) : 'Pending',
                'unit' => null,
                'meta' => $reading && $reading->ph !== null ? 'Within sample crop ranges' : 'No valid reading',
                'icon' => 'flask',
                'badge' => $reading && $reading->ph !== null ? 'In range' : 'Not measured',
                'tone' => $reading && $reading->ph !== null ? 'green' : 'gray',
                'note' => $reading ? 'Recorded ' . $reading->recorded_at->format('M d, g:i A') : 'No timestamp',
            ],
            [
                'name' => 'Soil moisture',
                'value' => $reading && $reading->moisture !== null ? (string) round($reading->moisture) : 'Pending',
                'unit' => $reading?->moisture_unit ?? 'raw index',
                'meta' => $reading && $reading->moisture_unit === 'raw index' ? 'Sensor is not % calibrated' : 'Calibrated reading',
                'icon' => 'droplet',
                'badge' => $reading && $reading->moisture_unit === 'raw index' ? 'Uncalibrated' : ($reading && $reading->moisture !== null ? 'In range' : 'Missing'),
                'tone' => $reading && $reading->moisture_unit === 'raw index' ? 'amber' : ($reading && $reading->moisture !== null ? 'green' : 'gray'),
                'note' => $reading ? 'Recorded ' . $reading->recorded_at->format('M d, g:i A') : 'No timestamp',
            ],
            [
                'name' => 'Soil temperature',
                'value' => $reading && $reading->temperature !== null ? (string) round($reading->temperature) : 'Pending',
                'unit' => '°C',
                'meta' => $reading && $reading->temperature !== null ? 'Valid sensor reading' : 'No valid reading',
                'icon' => 'temp',
                'badge' => $reading && $reading->temperature !== null ? 'Available' : 'Missing',
                'tone' => $reading && $reading->temperature !== null ? 'green' : 'gray',
                'note' => $reading ? 'Recorded ' . $reading->recorded_at->format('M d, g:i A') : 'No timestamp',
            ],
        ];

        // Format evaluated crops
        $crops = [];
        if ($assessment && $assessment->cropEvaluations->isNotEmpty()) {
            foreach ($assessment->cropEvaluations as $eval) {
                $crop = $eval->crop;
                $req = $crop->requirements;
                $crops[] = [
                    'name' => $crop->name,
                    'scientific' => $crop->scientific_name,
                    'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                    'status' => $eval->suitability_tier,
                    'tone' => $eval->tone,
                    'support' => $eval->supporting_factors,
                    'limit' => $eval->limiting_factors,
                    'image' => $crop->image_url,
                ];
            }
        }

        return view('report', [
            'title' => 'Assessment Report',
            'sampleId' => $sample->sample_code,
            'sample' => $sample,
            'reading' => $reading,
            'assessment' => $assessment,
            'crops' => $crops,
            'measurements' => $measurements,
        ]);
    }

    /**
     * Filterable and Paginable Sample History
     */
    public function history(Request $request)
    {
        $query = SoilSample::with(['latestReading', 'device', 'latestAssessment.cropEvaluations.crop']);

        // Search by sample code, name, location, or operator
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('sample_code', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%")
                  ->orWhere('location', 'like', "%{$term}%");
            });
        }

        // Filter by assessment status
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('assessment_status', strtolower($request->status));
        }

        // Filter by date
        if ($request->filled('date')) {
            $query->whereDate('collected_at', $request->date);
        }

        $paginated = $query->latest('collected_at')->paginate(10)->withQueryString();

        $samples = $paginated->map(function ($s) {
            $r = $s->latestReading;
            $assessment = $s->latestAssessment;

            $validCount = 0;
            if ($r) {
                if ($r->ph !== null) $validCount++;
                if ($r->temperature !== null) $validCount++;
                if ($r->moisture !== null) $validCount++;
            }

            $topCrops = 'Pending evaluation';
            if ($assessment && $assessment->cropEvaluations->isNotEmpty()) {
                $matched = $assessment->cropEvaluations
                    ->whereIn('suitability_tier', ['Strong Match', 'Potential Match'])
                    ->take(3)
                    ->map(fn($e) => $e->crop?->name)
                    ->filter()
                    ->implode(', ');
                $topCrops = $matched ?: 'Limited compatibility';
            }

            return [
                'id' => $s->sample_code,
                'name' => $s->name,
                'date' => $s->collected_at->format('M d, g:i A'),
                'location' => $s->location ?? 'Unspecified',
                'ph' => $r && $r->ph !== null ? number_format($r->ph, 1) : '—',
                'moisture' => $r && $r->moisture !== null ? "{$r->moisture} {$r->moisture_unit}" : '—',
                'temperature' => $r && $r->temperature !== null ? "{$r->temperature}°C" : '—',
                'assessment' => ucfirst($s->assessment_status),
                'device' => $s->device?->name ?? 'ESP32-01',
                'valid_count' => "{$validCount} of 3",
                'crops' => $topCrops,
            ];
        })->toArray();

        return view('history', [
            'title' => 'Sample History',
            'samples' => $samples,
            'paginator' => $paginated,
        ]);
    }

    /**
     * Reports and Exports view
     */
    public function reports()
    {
        $samples = SoilSample::with(['latestReading', 'latestAssessment'])
            ->latest('collected_at')
            ->take(10)
            ->get();

        return view('reports', [
            'title' => 'Reports',
            'samples' => $samples,
        ]);
    }

    /**
     * Get the active soil sample configuration for ESP32 hardware testing
     */
    public static function getActiveSampleData(): array
    {
        return \Illuminate\Support\Facades\Cache::get('esp32_active_sample', [
            'sample_code' => 'S1',
            'sample_id'   => 'SS-2025-018',
            'name'        => 'South plot — Bed 01',
            'base_ph'     => 4.01,
            'quality'     => 'ACIDIC',
            'updated_at'  => now()->toIso8601String(),
        ]);
    }

    /**
     * API / Web endpoint to retrieve active sample
     */
    public function getActiveSample()
    {
        return response()->json([
            'success' => true,
            'active_sample' => self::getActiveSampleData(),
        ]);
    }

    /**
     * API endpoint to get the latest live reading transmitted by the ESP32
     */
    public function getLatestReading()
    {
        $reading = SensorReading::latest('recorded_at')->first();
        $device = IotDevice::where('device_uid', 'ESP32-01')->first() ?? IotDevice::first();
        $activeSample = self::getActiveSampleData();

        return response()->json([
            'success' => true,
            'device' => [
                'uid' => $device?->device_uid ?? 'ESP32-01',
                'status' => 'online',
                'last_seen' => $reading ? $reading->recorded_at->diffForHumans() : 'Just now',
            ],
            'reading' => [
                'ph' => $reading && $reading->ph !== null ? round($reading->ph, 2) : 6.86,
                'moisture' => $reading && $reading->moisture !== null ? round($reading->moisture, 1) : 0,
                'temperature' => $reading && $reading->temperature !== null ? round($reading->temperature, 1) : 25.0,
                'recorded_at' => $reading?->recorded_at?->toIso8601String(),
                'time_ago' => $reading ? $reading->recorded_at->diffForHumans() : 'Just now',
            ],
            'active_sample' => $activeSample,
        ]);
    }

    /**
     * Advance the active soil sample profile sequentially (S1 -> S2 -> S3 -> S1)
     */
    public static function advanceActiveSample(): array
    {
        $sequence = ['S1', 'S2', 'S3'];
        $samples = [
            'S1' => [
                'sample_code' => 'S1',
                'sample_id'   => 'SS-2025-018',
                'name'        => 'South plot — Bed 01',
                'base_ph'     => 4.01,
                'quality'     => 'ACIDIC',
            ],
            'S2' => [
                'sample_code' => 'S2',
                'sample_id'   => 'SS-2025-018',
                'name'        => 'South plot — Bed 02',
                'base_ph'     => 6.86,
                'quality'     => 'OPTIMAL',
            ],
            'S3' => [
                'sample_code' => 'S3',
                'sample_id'   => 'SS-2025-018',
                'name'        => 'South plot — Bed 03',
                'base_ph'     => 9.18,
                'quality'     => 'ALKALINE',
            ],
        ];

        $current = self::getActiveSampleData();
        $currentCode = $current['sample_code'] ?? 'S3';
        $currentIndex = array_search($currentCode, $sequence);
        $nextIndex = ($currentIndex === false) ? 0 : (($currentIndex + 1) % count($sequence));
        $nextCode = $sequence[$nextIndex];

        $active = $samples[$nextCode];
        $active['updated_at'] = now()->toIso8601String();
        \Illuminate\Support\Facades\Cache::forever('esp32_active_sample', $active);

        return $active;
    }

    /**
     * API / Web endpoint to advance sample silently on "+ New Soil Test" click
     */
    public function advanceSample()
    {
        $active = self::advanceActiveSample();
        return response()->json([
            'success' => true,
            'message' => "Advanced to {$active['name']}",
            'active_sample' => $active,
        ]);
    }

    /**
     * API / Web endpoint to switch active sample from website buttons
     */
    public function setActiveSample(Request $request)
    {
        $samples = [
            'S1' => [
                'sample_code' => 'S1',
                'sample_id'   => 'SS-2025-018',
                'name'        => 'Sample 1: Acidic Peat',
                'base_ph'     => 4.01,
                'quality'     => 'ACIDIC',
            ],
            'S2' => [
                'sample_code' => 'S2',
                'sample_id'   => 'SS-2025-018',
                'name'        => 'Sample 2: Garden Loam',
                'base_ph'     => 6.86,
                'quality'     => 'OPTIMAL',
            ],
            'S3' => [
                'sample_code' => 'S3',
                'sample_id'   => 'SS-2025-018',
                'name'        => 'Sample 3: Chalky Clay',
                'base_ph'     => 9.18,
                'quality'     => 'ALKALINE',
            ],
        ];

        $code = strtoupper($request->input('sample_code', 'S1'));
        if (!isset($samples[$code])) {
            return response()->json([
                'success' => false,
                'error' => "Invalid sample code: {$code}. Expected S1, S2, or S3.",
            ], 422);
        }

        $active = $samples[$code];
        $active['updated_at'] = now()->toIso8601String();
        \Illuminate\Support\Facades\Cache::forever('esp32_active_sample', $active);

        return response()->json([
            'success' => true,
            'message' => "Switched active hardware test profile to {$active['name']}",
            'active_sample' => $active,
        ]);
    }

    /**
     * Device Monitoring view connected to IoT Device records
     */
    public function device()
    {
        $device = IotDevice::where('device_uid', 'ESP32-01')->first() ?? IotDevice::first();
        $recentReadings = SensorReading::latest('recorded_at')->take(5)->get();
        $activeSample = self::getActiveSampleData();

        return view('device', [
            'title' => 'Device Monitoring',
            'device' => $device,
            'recentReadings' => $recentReadings,
            'activeSample' => $activeSample,
        ]);
    }

    /**
     * User Settings view
     */
    public function settings()
    {
        $user = User::first();

        return view('settings', [
            'title' => 'Settings',
            'user' => $user,
        ]);
    }
}
