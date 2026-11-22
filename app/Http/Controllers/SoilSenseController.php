<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFertilizerRefreshRequest;
use App\Http\Requests\StoreSoilSampleRequest;
use App\Models\CropProfile;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use App\Models\User;
use App\Services\FertilizerRecommendationService;
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
                'min_ph' => $req?->min_ph,
                'max_ph' => $req?->max_ph,
                'measured_ph' => null,
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
            [
                'name' => 'Electrical conductivity',
                'value' => $reading && $reading->ec !== null ? number_format($reading->ec, 2) : 'Pending',
                'unit' => $reading?->ec_unit ?? 'mS/cm',
                'meta' => $reading && $reading->ec !== null ? 'Valid reading' : 'No valid reading received',
                'icon' => 'bolt',
                'badge' => $reading && $reading->ec !== null ? 'In range' : 'Not measured',
                'tone' => $reading && $reading->ec !== null ? 'green' : 'gray',
                'note' => $reading && $reading->ec !== null ? 'Updated ' . $reading->recorded_at->diffForHumans() : 'Awaiting sensor',
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
                    'min_ph' => $req?->min_ph,
                    'max_ph' => $req?->max_ph,
                    'measured_ph' => $reading?->ph,
                    'status' => $eval->suitability_tier,
                    'tone' => $eval->tone,
                    'support' => $eval->supporting_factors,
                    'limit' => $eval->limiting_factors,
                    'image' => $crop->image_url,
                ];
            }
        } else {
            $crops = CropProfile::with('requirements')->take(6)->get()->map(function ($crop) use ($reading) {
                $req = $crop->requirements;
                return [
                    'name' => $crop->name,
                    'scientific' => $crop->scientific_name,
                    'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                    'min_ph' => $req?->min_ph,
                    'max_ph' => $req?->max_ph,
                    'measured_ph' => $reading?->ph,
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

        $totalSamplesCount = SoilSample::count();

        // Build chronological real pH trend series for 14-day and 30-day windows from a single query
        $cutoff30 = Carbon::now()->subDays(30);
        $cutoff14 = Carbon::now()->subDays(14);
        $phSamples = SoilSample::with('latestReading')
            ->orderBy('collected_at', 'desc')
            ->take(60)
            ->get(['id', 'collected_at'])
            ->filter(fn ($s) => $s->latestReading && $s->latestReading->ph !== null)
            ->values();

        $buildPhSeries = function (int $days, Carbon $cutoff) use ($phSamples) {
            $rows = $phSamples
                ->filter(fn ($s) => $s->collected_at >= $cutoff)
                ->reverse()
                ->values();

            if ($rows->count() < 2) {
                $rows = $phSamples
                    ->take($days)
                    ->reverse()
                    ->values();
            }

            $labels = $rows->map(fn ($s) => $s->collected_at->format('M d'))->all();
            $values = $rows->map(fn ($s) => round((float) $s->latestReading->ph, 2))->all();

            $deltaText = 'Single recorded pH reading';
            if (count($values) >= 2) {
                $diff = round($values[count($values) - 1] - $values[count($values) - 2], 2);
                $sign = $diff > 0 ? '+' : '';
                $deltaText = "{$sign}{$diff} vs previous sample";
            } elseif (count($values) === 0) {
                $deltaText = 'No pH readings recorded yet';
            }

            return [
                'labels' => $labels,
                'values' => $values,
                'delta_text' => $deltaText,
            ];
        };

        $phTrendSeries = [
            '14' => $buildPhSeries(14, $cutoff14),
            '30' => $buildPhSeries(30, $cutoff30),
        ];

        // Device telemetry (reuse across controller + sidebar layout within this request)
        $device = $latestSample?->device_id && $latestSample->device?->device_uid === 'ESP32-01'
            ? $latestSample->device
            : (IotDevice::where('device_uid', 'ESP32-01')->first() ?? IotDevice::first());
        request()?->attributes->set('soilsense.sidebar_device', $device);

        return view('dashboard', [
            'title' => 'Overview',
            'latestSample' => $latestSample,
            'reading' => $reading,
            'assessment' => $assessment,
            'device' => $device,
            'crops' => $crops,
            'measurements' => $measurements,
            'samples' => $samples,
            'totalSamplesCount' => $totalSamplesCount,
            'phTrendSeries' => $phTrendSeries,
        ]);
    }

    /**
     * Soil Analysis page & New Test form
     */
    public function analysis()
    {
        $devices = IotDevice::all();
        $latestReading = SensorReading::latest('recorded_at')->first();

        return view('analysis', [
            'title' => 'Soil Analysis',
            'devices' => $devices,
            'latestReading' => $latestReading,
        ]);
    }

    /**
     * Store new soil sample, record reading, and perform assessment transaction
     */
    public function storeSample(
        StoreSoilSampleRequest $request,
        SoilAssessmentService $assessmentService,
        FertilizerRecommendationService $fertilizerService
    ) {
        $validated = $request->validated();

        $sample = DB::transaction(function () use ($validated, $assessmentService, $fertilizerService) {
            $count = SoilSample::count() + 1;
            $sampleCode = 'SS-' . date('Y') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

            $user = User::first();
            $sourceType = $validated['is_demo'] ?? true ? 'demo' : 'live';

            $sample = SoilSample::create([
                'sample_code' => $sampleCode,
                'name' => $validated['name'],
                'location' => $validated['location'] ?? 'Demo Farm, Laguna',
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'soil_type' => $validated['soil_type'] ?? null,
                'collected_at' => Carbon::parse($validated['collected_at']),
                'testing_method' => $validated['testing_method'],
                'source_type' => $sourceType,
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

            $assessment = $assessmentService->evaluateSample($sample, $reading);

            if (
                $reading->ph !== null
                || $reading->moisture !== null
                || $reading->temperature !== null
                || $reading->ec !== null
            ) {
                try {
                    $fertilizerService->recommend($sample, $reading, $assessment);
                } catch (\Throwable $e) {
                    // Fail safe so sample registration completes even if external forecast/service fails
                }
            }

            return $sample;
        });

        return redirect()->route('report', ['id' => $sample->sample_code])
            ->with('success', "Soil sample {$sample->sample_code} registered and assessed successfully!");
    }

    /**
     * Recalculate fertilizer recommendation for an existing sample using 4-sensor telemetry + crop/soil type
     */
    public function refreshFertilizer(
        StoreFertilizerRefreshRequest $request,
        string $id,
        FertilizerRecommendationService $fertilizerService
    ) {
        $validated = $request->validated();

        $sample = SoilSample::where('sample_code', $id)
            ->orWhere('id', is_numeric($id) ? (int) $id : 0)
            ->with(['latestReading', 'latestAssessment.cropEvaluations.crop'])
            ->firstOrFail();

        $reading = $sample->latestReading;
        if (
            !$reading
            || ($reading->ph === null && $reading->moisture === null && $reading->temperature === null && $reading->ec === null)
        ) {
            return redirect()->route('report', ['id' => $sample->sample_code])
                ->withErrors(['telemetry' => 'At least one soil sensor measurement (pH, moisture, temperature, or EC) is required to recalculate a fertilizer recommendation.']);
        }

        DB::transaction(function () use ($sample, $reading, $validated, $fertilizerService) {
            $sampleUpdates = [];
            foreach (['soil_type', 'latitude', 'longitude'] as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                    $sampleUpdates[$field] = $validated[$field];
                }
            }
            if ($sampleUpdates !== []) {
                $sample->update($sampleUpdates);
            }

            $fertilizerService->recommend(
                $sample,
                $reading,
                $sample->latestAssessment,
                $validated['crop'] ?? null
            );
        });

        return redirect()->route('report', ['id' => $sample->sample_code])
            ->with('success', "Fertilizer recommendation recalculated for {$sample->sample_code}.");
    }

    /**
     * Crop Recommendations catalog
     */
    public function crops(Request $request)
    {
        $latestSample = SoilSample::with(['latestReading', 'latestAssessment.cropEvaluations'])->latest('collected_at')->first();
        $latestReading = $latestSample?->latestReading;
        $latestAssessment = $latestSample?->latestAssessment;
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

        $crops = $query->get()->map(function ($crop) use ($latestAssessment, $latestReading) {
            $req = $crop->requirements;
            $eval = $latestAssessment?->cropEvaluations->firstWhere('crop_profile_id', $crop->id);

            return [
                'name' => $crop->name,
                'scientific' => $crop->scientific_name,
                'category' => $crop->category,
                'range' => $req ? "pH {$req->min_ph}–{$req->max_ph}" : 'Pending',
                'min_ph' => $req?->min_ph,
                'max_ph' => $req?->max_ph,
                'measured_ph' => $latestReading?->ph,
                'min_temp' => $req?->min_temperature,
                'max_temp' => $req?->max_temperature,
                'min_moisture' => $req?->min_moisture,
                'max_moisture' => $req?->max_moisture,
                'max_ec' => $req?->max_ec,
                'status' => $eval?->suitability_tier ?? 'Potential Match',
                'tone' => $eval?->tone ?? 'green',
                'support' => $eval?->supporting_factors ?? 'pH and temperature align with documented requirements',
                'limit' => $eval?->limiting_factors ?? 'Moisture is uncalibrated; EC and nutrients unassessed',
                'image' => $crop->image_url,
                'score' => $eval?->match_score,
            ];
        })->toArray();

        $usableParams = (int) ($latestAssessment?->usable_parameters_count ?? 0);
        $totalParams = (int) ($latestAssessment?->total_parameters_count ?? 4);

        return view('crops', [
            'title' => 'Crop Recommendations',
            'crops' => $crops,
            'latestSample' => $latestSample,
            'usableParams' => $usableParams,
            'incompleteParams' => max(0, $totalParams - $usableParams),
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
        $relations = [
            'latestReading.latestFertilizerRecommendation',
            'latestAssessment.cropEvaluations.crop.requirements',
            'device',
        ];

        $sample = null;
        if ($id) {
            $sample = SoilSample::with($relations)->where('sample_code', $id)->first();
        }

        if (!$sample) {
            $sample = SoilSample::with($relations)->latest('collected_at')->firstOrFail();
        }

        $reading = $sample->latestReading;
        $assessment = $sample->latestAssessment;
        $fertilizerRecommendation = $reading?->latestFertilizerRecommendation;

        $hasUsableTelemetry = $reading && (
            $reading->ph !== null
            || $reading->moisture !== null
            || $reading->temperature !== null
            || $reading->ec !== null
        );

        if ($hasUsableTelemetry && !$fertilizerRecommendation) {
            try {
                $fertilizerRecommendation = app(FertilizerRecommendationService::class)->recommend(
                    $sample,
                    $reading,
                    $assessment
                );
                $reading->setRelation('latestFertilizerRecommendation', $fertilizerRecommendation);
            } catch (\Throwable $e) {
                $fertilizerRecommendation = null;
            }
        }

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
            [
                'name' => 'Electrical conductivity',
                'value' => $reading && $reading->ec !== null ? number_format($reading->ec, 2) : 'Pending',
                'unit' => $reading?->ec_unit ?? 'mS/cm',
                'meta' => $reading && $reading->ec !== null ? 'Valid sensor reading' : 'No valid reading received',
                'icon' => 'bolt',
                'badge' => $reading && $reading->ec !== null ? 'In range' : 'Not measured',
                'tone' => $reading && $reading->ec !== null ? 'green' : 'gray',
                'note' => $reading && $reading->ec !== null ? 'Recorded ' . $reading->recorded_at->format('M d, g:i A') : 'Awaiting sensor',
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

        $cropOptions = CropProfile::orderBy('name')->pluck('name')->all();

        return view('report', [
            'title' => 'Assessment Report',
            'sampleId' => $sample->sample_code,
            'sample' => $sample,
            'reading' => $reading,
            'assessment' => $assessment,
            'fertilizerRecommendation' => $fertilizerRecommendation,
            'lowConfidenceThreshold' => (float) config('fertilizer.ml_service.low_confidence_threshold', 0.55),
            'cropOptions' => $cropOptions,
            'crops' => $crops,
            'measurements' => $measurements,
        ]);
    }

    /**
     * Filterable and Paginable Sample History
     */
    public function history(Request $request)
    {
        $filterQuery = SoilSample::query();

        // Search by sample code, name, location, or operator
        if ($request->filled('search')) {
            $term = $request->search;
            $filterQuery->where(function ($q) use ($term) {
                $q->where('sample_code', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%")
                  ->orWhere('location', 'like', "%{$term}%");
            });
        }

        // Filter by assessment status
        if ($request->filled('status') && $request->status !== 'All') {
            $filterQuery->where('assessment_status', strtolower($request->status));
        }

        // Filter by date
        if ($request->filled('date')) {
            $filterQuery->whereDate('collected_at', $request->date);
        }

        // Build chronological 4-sensor trend series across matching samples using lightweight column/relation selection
        $trendSamples = (clone $filterQuery)
            ->with('latestReading')
            ->orderBy('collected_at', 'asc')
            ->get(['id', 'sample_code', 'collected_at']);

        $trendLabels = [];
        $trendPh = [];
        $trendEc = [];
        $trendMoisture = [];
        $trendTemperature = [];
        $hasSensorData = false;

        foreach ($trendSamples as $trendSample) {
            $r = $trendSample->latestReading;
            $ph = $r && $r->ph !== null ? round((float) $r->ph, 2) : null;
            $ec = $r && $r->ec !== null ? round((float) $r->ec, 2) : null;
            $moisture = $r && $r->moisture !== null ? round((float) $r->moisture, 1) : null;
            $temperature = $r && $r->temperature !== null ? round((float) $r->temperature, 1) : null;

            if ($ph !== null || $ec !== null || $moisture !== null || $temperature !== null) {
                $hasSensorData = true;
                $trendLabels[] = $trendSample->sample_code . ' (' . $trendSample->collected_at->format('M d') . ')';
                $trendPh[] = $ph;
                $trendEc[] = $ec;
                $trendMoisture[] = $moisture;
                $trendTemperature[] = $temperature;
            }
        }

        $sensorTrend = [
            'labels' => $trendLabels,
            'ph' => $trendPh,
            'ec' => $trendEc,
            'moisture' => $trendMoisture,
            'temperature' => $trendTemperature,
            'has_data' => $hasSensorData,
        ];

        $paginated = $filterQuery
            ->with([
                'latestReading.latestFertilizerRecommendation',
                'device',
                'latestAssessment.cropEvaluations.crop',
            ])
            ->latest('collected_at')
            ->paginate(10)
            ->withQueryString();

        $samples = $paginated->map(function ($s) {
            $r = $s->latestReading;
            $assessment = $s->latestAssessment;
            $fert = $r?->latestFertilizerRecommendation;

            $validCount = 0;
            if ($r) {
                if ($r->ph !== null) $validCount++;
                if ($r->temperature !== null) $validCount++;
                if ($r->moisture !== null) $validCount++;
                if ($r->ec !== null) $validCount++;
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
                'ec' => $r && $r->ec !== null ? "{$r->ec} {$r->ec_unit}" : 'Pending',
                'fertilizer' => $fert?->predicted_fertilizer,
                'assessment' => ucfirst($s->assessment_status),
                'device' => $s->device?->name ?? 'ESP32-01',
                'valid_count' => "{$validCount} of 4",
                'crops' => $topCrops,
            ];
        })->toArray();

        return view('history', [
            'title' => 'Sample History',
            'samples' => $samples,
            'paginator' => $paginated,
            'sensorTrend' => $sensorTrend,
        ]);
    }

    /**
     * Reports and Exports view
     */
    public function reports(Request $request)
    {
        $days = (int) $request->input('days', 0);
        $query = SoilSample::with(['latestReading', 'latestAssessment'])
            ->latest('collected_at');

        if ($days > 0) {
            $query->where('collected_at', '>=', Carbon::now()->subDays($days));
        }

        $samples = $query->take(20)->get();

        return view('reports', [
            'title' => 'Reports',
            'samples' => $samples,
            'selectedDays' => $days,
        ]);
    }

    /**
     * Device Monitoring view connected to IoT Device records
     */
    public function device()
    {
        $device = IotDevice::where('device_uid', 'ESP32-01')->first() ?? IotDevice::first();
        request()?->attributes->set('soilsense.sidebar_device', $device);
        $recentReadings = SensorReading::latest('recorded_at')->take(5)->get();

        return view('device', [
            'title' => 'Device Monitoring',
            'device' => $device,
            'recentReadings' => $recentReadings,
        ]);
    }

    /**
     * User Settings view
     */
    public function settings()
    {
        $user = User::first();
        request()?->attributes->set('soilsense.current_user', $user);

        return view('settings', [
            'title' => 'Settings',
            'user' => $user,
        ]);
    }
}
