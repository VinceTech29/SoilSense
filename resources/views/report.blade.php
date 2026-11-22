@extends('layouts.app', ['title' => 'Assessment Report'])

@section('content')
    @php
        $usableCount = (int) ($assessment?->usable_parameters_count ?? 0);
        $totalCount = (int) ($assessment?->total_parameters_count ?? 4);
        $completenessPct = $totalCount > 0 ? round(($usableCount / $totalCount) * 100) : 0;

        $phCalibration = $reading && $reading->ph !== null ? 'pH: recorded' : 'pH: pending';
        $moistureCalibration = $reading && $reading->moisture !== null
            ? ($reading->moisture_unit === 'raw index' ? 'Moisture: uncalibrated (raw)' : 'Moisture: calibrated (% VWC)')
            : 'Moisture: missing';
    @endphp

    <div class="report-head">
        <div>
            <a href="{{ route('dashboard') }}" class="back-link">
                <x-icon name="chevron" :size="14" class="icon-back-flip" />
                <span>Back to overview</span>
            </a>
            <span class="section-kicker">Soil assessment report</span>
            <h1>Sample {{ $sample->sample_code }}: {{ $sample->name }}</h1>
            <p>Collected {{ $sample->collected_at->format('M d, Y · g:i A') }} · {{ $sample->location ?? 'Unspecified Location' }} · {{ ucfirst($sample->source_type) }} data</p>
        </div>
        <div>
            <a href="{{ route('settings.export.samples') }}" class="btn btn-secondary">
                <x-icon name="download" :size="16" />
                <span>Export CSV</span>
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    @if(session('success'))
        <div class="notice success" role="status">
            <x-icon name="check" />
            <div>
                <b>Update saved</b>
                <span>{{ session('success') }}</span>
            </div>
        </div>
    @endif

    <!-- Preliminary Warning Notice -->
    @if($sample->source_type === 'demo')
        <div class="notice demo">
            <x-icon name="warning" />
            <div>
                <b>Preliminary prototype report</b>
                <span>This report uses simulated prototype data. It is not a laboratory certificate and should not be used as the sole basis for agricultural treatment or planting decisions.</span>
            </div>
        </div>
    @endif

    <!-- Executive Summary Card -->
    <section class="card report-summary">
        <div>
            <x-badge :tone="($assessment?->status ?? 'preliminary') === 'reviewed' ? 'green' : 'amber'">
                {{ ucfirst($assessment?->status ?? 'Incomplete evidence') }}
            </x-badge>
            <h2>Executive summary</h2>
            <p>{{ $assessment?->summary ?? 'Available sensor readings are evaluated against documented crop ranges. Uncalibrated moisture and missing channels remain transparently flagged.' }}</p>
        </div>
        <div class="report-complete">
            <span>Assessment completeness</span>
            <b>{{ $usableCount }} of {{ $totalCount }} usable</b>
            <div>
                <i style="width: {{ $completenessPct }}%;"></i>
            </div>
            <small>Based on defined measurement requirements</small>
        </div>
    </section>

    <!-- Recorded Evidence Section -->
    <section class="report-section">
        <div class="section-title">
            <div>
                <span class="section-kicker">Recorded evidence</span>
                <h2>Measurement results</h2>
            </div>
        </div>
        <div class="metrics-grid">
            @foreach($measurements as $item)
                <x-measurement-card :item="$item" />
            @endforeach
        </div>
    </section>

    <!-- Crop Suitability Recommendations Block -->
    <section class="result-recommendations">
        <div class="recommendation-heading">
            <div>
                <span class="section-kicker">Generated from this soil test</span>
                <h2>Vegetables you may consider planting</h2>
                <p>Candidates match available valid sensor readings. Uncalibrated moisture and unmeasured laboratory parameters remain noted below.</p>
            </div>
            <a href="{{ route('crops') }}" class="btn btn-secondary">Explore all crop matches</a>
        </div>

        <div class="result-crop-grid">
            @forelse(array_slice($crops, 0, 3) as $index => $crop)
                <article class="card result-crop">
                    <img src="{{ $crop['image'] }}" alt="{{ $crop['name'] }} crop" loading="lazy">
                    <div class="result-crop-content">
                        <div class="result-crop-title">
                            <span class="crop-rank">0{{ $index + 1 }}</span>
                            <div>
                                <h3>{{ $crop['name'] }}</h3>
                                <i>{{ $crop['scientific'] }}</i>
                            </div>
                            <x-badge :tone="$crop['tone']">{{ $crop['status'] }}</x-badge>
                        </div>

                        <div class="result-match">
                            <span>
                                <x-icon name="check" :size="15" />
                                {{ $crop['support'] }}
                            </span>
                        </div>

                        <div class="result-caution">
                            <x-icon name="warning" :size="16" />
                            <span>
                                <b>Evidence note</b>
                                {{ $crop['limit'] }}. Verify before planting.
                            </span>
                        </div>

                        <a href="{{ route('crop.detail', ['name' => strtolower(str_replace(' ', '-', $crop['name']))]) }}" class="btn btn-secondary">
                            <span>Why this crop may fit</span>
                            <x-icon name="chevron" :size="14" />
                        </a>
                    </div>
                </article>
            @empty
                <p style="grid-column: 1 / -1; color: var(--muted);">No crop evaluations available for this sample.</p>
            @endforelse
        </div>

        <div class="recommendation-next card">
            <span class="metric-icon tone-amber"><x-icon name="flask" /></span>
            <div>
                <b>Before deciding what to plant</b>
                <p>{{ $assessment?->recommended_next_steps ?? 'Confirm moisture sensor calibration and consult your municipal agriculturist for local variety suitability.' }}</p>
            </div>
            <a href="{{ route('analysis') }}" class="btn btn-primary">Run another soil test</a>
        </div>
    </section>

    <!-- Fertilizer Recommendation Section -->
    @php
        $gradeLabel = $fertilizerRecommendation
            ? config("fertilizer.grades.{$fertilizerRecommendation->predicted_fertilizer}.label")
            : null;
        $confidencePct = $fertilizerRecommendation
            ? (int) round($fertilizerRecommendation->confidence * 100)
            : null;
        $isLowConfidence = $fertilizerRecommendation
            && $fertilizerRecommendation->confidence < ($lowConfidenceThreshold ?? 0.55);
        $topPredictions = is_array($fertilizerRecommendation?->top_predictions)
            ? array_slice($fertilizerRecommendation->top_predictions, 0, 3)
            : [];
        $splitSchedule = is_array($fertilizerRecommendation?->split_schedule)
            ? $fertilizerRecommendation->split_schedule
            : [];
        $rainAdvisory = is_array($fertilizerRecommendation?->rain_advisory)
            ? $fertilizerRecommendation->rain_advisory
            : null;
        $rainStatus = $rainAdvisory['status'] ?? 'manual_check';
        $rainApiFailed = (bool) ($rainAdvisory['api_failed'] ?? false);
    @endphp

    <section class="card fertilizer-card" id="fertilizerRecommendationSection">
        <div class="fertilizer-head">
            <div>
                <span class="section-kicker">Fertilizer decision support</span>
                <h2>Fertilizer Recommendation</h2>
                <p>4-sensor telemetry (pH, EC, moisture, temperature) paired with crop baseline dosage targets and 72-hour rainfall screening.</p>
            </div>
            @if($fertilizerRecommendation)
                <div class="fertilizer-badges">
                    <x-badge :tone="$isLowConfidence ? 'amber' : 'green'">
                        {{ $confidencePct }}% confidence
                    </x-badge>
                    @if($isLowConfidence)
                        <x-badge tone="amber">Low confidence</x-badge>
                    @endif
                    @if($fertilizerRecommendation->fallback_used)
                        <x-badge tone="amber">Rule-based fallback</x-badge>
                    @endif
                    <x-badge tone="gray" :dot="false">Model: {{ $fertilizerRecommendation->model_version }}</x-badge>
                </div>
            @else
                <div class="fertilizer-badges">
                    <x-badge tone="gray">Awaiting sensor telemetry</x-badge>
                </div>
            @endif
        </div>

        @if($fertilizerRecommendation)
            <!-- Primary Recommendation Summary -->
            <div class="fertilizer-hero">
                <div class="fert-stat">
                    <span>Target crop</span>
                    <b>{{ $fertilizerRecommendation->crop }}</b>
                    <small>{{ $sample->soil_type ? $sample->soil_type . ' soil profile' : 'Default Loamy soil profile' }}</small>
                </div>
                <div class="fert-stat primary">
                    <span>Predicted fertilizer</span>
                    <b>{{ $fertilizerRecommendation->predicted_fertilizer }}</b>
                    <small>{{ $gradeLabel ?? 'Recommended nutrient product' }}</small>
                </div>
                <div class="fert-stat">
                    <span>Total dosage rate</span>
                    <b>{{ number_format($fertilizerRecommendation->dose_kg_ha, 1) }} kg/ha</b>
                    <small>
                        @if(count($splitSchedule) > 1)
                            Split across {{ count($splitSchedule) }} applications
                        @elseif(count($splitSchedule) === 1)
                            Single application schedule
                        @else
                            Zero crop baseline target
                        @endif
                    </small>
                </div>
            </div>

            <!-- Rain Guard 72h Advisory Banner -->
            @if($rainAdvisory)
                @if($rainStatus === 'proceed' && !$rainApiFailed)
                    <div class="rain-banner proceed" role="status">
                        <x-icon name="check" :size="18" />
                        <div class="rain-banner-body">
                            <div class="rain-banner-top">
                                <x-badge tone="green">Proceed</x-badge>
                                <b>Rain Guard 72h Advisory: Proceed with Application</b>
                            </div>
                            <p>{{ $rainAdvisory['message'] ?? '72-hour rainfall outlook is favorable for fertilizer application.' }}</p>
                            <div class="rain-meta">
                                @if(isset($rainAdvisory['precipitation_sum_mm']))
                                    <span>72h Rain Sum: {{ number_format((float) $rainAdvisory['precipitation_sum_mm'], 1) }} mm</span>
                                @endif
                                @if(isset($rainAdvisory['max_precipitation_probability']))
                                    <span>Max Rain Probability: {{ $rainAdvisory['max_precipitation_probability'] }}%</span>
                                @endif
                                @if(!empty($rainAdvisory['location_label']))
                                    <span>Location: {{ $rainAdvisory['location_label'] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @elseif($rainStatus === 'delay' && !$rainApiFailed)
                    <div class="rain-banner delay" role="alert">
                        <x-icon name="warning" :size="18" />
                        <div class="rain-banner-body">
                            <div class="rain-banner-top">
                                <x-badge tone="red">Delay Application</x-badge>
                                <b>Rain Guard 72h Advisory: Delay Application</b>
                            </div>
                            <p>{{ $rainAdvisory['message'] ?? 'Heavy rain risk detected over the next 72 hours. Delay fertilizer application to prevent runoff.' }}</p>
                            <div class="rain-meta">
                                @if(isset($rainAdvisory['precipitation_sum_mm']))
                                    <span>72h Rain Sum: {{ number_format((float) $rainAdvisory['precipitation_sum_mm'], 1) }} mm</span>
                                @endif
                                @if(isset($rainAdvisory['max_precipitation_probability']))
                                    <span>Max Rain Probability: {{ $rainAdvisory['max_precipitation_probability'] }}%</span>
                                @endif
                                @if(!empty($rainAdvisory['suggested_dry_window']['label']))
                                    <span>Suggested Dry Window: {{ $rainAdvisory['suggested_dry_window']['label'] }}</span>
                                @endif
                                @if(!empty($rainAdvisory['location_label']))
                                    <span>Location: {{ $rainAdvisory['location_label'] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="rain-banner manual" role="status">
                        <x-icon name="alert" :size="18" />
                        <div class="rain-banner-body">
                            <div class="rain-banner-top">
                                <x-badge tone="amber">Check Weather Manually</x-badge>
                                <b>Rain Guard 72h Advisory: Check Weather Manually</b>
                            </div>
                            <p>{{ $rainAdvisory['message'] ?? 'Live 72-hour rainfall forecast is unavailable. Check local weather conditions manually before field application.' }}</p>
                            @if(!empty($rainAdvisory['location_label']))
                                <div class="rain-meta">
                                    <span>Location: {{ $rainAdvisory['location_label'] }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endif

            <!-- Top-3 Predictions & Split Application Schedule -->
            <div class="fertilizer-grid">
                <div class="fert-panel">
                    <h3>Top-3 fertilizer predictions</h3>
                    @if(!empty($topPredictions))
                        <div class="pred-list">
                            @foreach($topPredictions as $idx => $prediction)
                                @php
                                    $probPct = round(((float) ($prediction['probability'] ?? 0)) * 100, 1);
                                @endphp
                                <div>
                                    <div class="pred-row-head">
                                        <b>{{ $idx + 1 }}. {{ $prediction['fertilizer'] ?? 'Unknown' }}</b>
                                        <span>{{ number_format($probPct, 1) }}%</span>
                                    </div>
                                    <div class="pred-track">
                                        <div class="pred-fill {{ $idx > 0 ? 'secondary' : '' }}" style="width: {{ min(100, max(0, $probPct)) }}%;"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p style="margin: 0; font-size: 12px;">No probability breakdown recorded for this prediction.</p>
                    @endif
                </div>

                <div class="fert-panel">
                    <h3>Split application schedule</h3>
                    @if(count($splitSchedule) > 0)
                        <div class="table-scroll">
                            <table class="split-table">
                                <thead>
                                    <tr>
                                        <th>App #</th>
                                        <th>Day Offset</th>
                                        <th>Stage</th>
                                        <th>Dose (kg/ha)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($splitSchedule as $step)
                                        <tr>
                                            <td><b>#{{ $step['application_number'] ?? $loop->iteration }}</b></td>
                                            <td>{{ ($step['day_offset'] ?? 0) === 0 ? 'Day 0' : 'Day +' . $step['day_offset'] }}</td>
                                            <td>{{ $step['stage'] ?? 'Scheduled application' }}</td>
                                            <td><b>{{ number_format((float) ($step['dose_kg_ha'] ?? 0), 1) }} kg/ha</b></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if(count($splitSchedule) === 1)
                            <p class="split-note">Single application: total rate is within the per-application cap, so splitting is not required.</p>
                        @endif
                    @else
                        <p style="margin: 0; font-size: 12px;">No scheduled application required for this crop baseline rate (0.0 kg/ha).</p>
                    @endif
                </div>
            </div>

            <!-- Compact Crop & Soil Type Recalculation Form -->
            <details class="fert-recalc-details" {{ $errors->hasAny(['soil_type', 'crop', 'latitude', 'longitude', 'telemetry']) ? 'open' : '' }}>
                <summary>
                    <span>Adjust Crop or Soil Type &amp; Recalculate Recommendation</span>
                    <x-icon name="chevron" :size="15" />
                </summary>

                <form method="POST" action="{{ route('report.fertilizer.refresh', ['id' => $sample->sample_code]) }}" id="refreshFertilizerForm">
                    @csrf
                    <div class="fert-form-grid">
                        <label>
                            <span>Target crop override (optional)</span>
                            <select name="crop">
                                <option value="">Auto (top suitable crop)</option>
                                @foreach(($cropOptions ?? []) as $cropOpt)
                                    <option value="{{ $cropOpt }}" {{ old('crop', $fertilizerRecommendation->crop) === $cropOpt ? 'selected' : '' }}>{{ $cropOpt }}</option>
                                @endforeach
                            </select>
                            @error('crop') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                        <label>
                            <span>Soil type (optional)</span>
                            <select name="soil_type">
                                <option value="">Keep current ({{ $sample->soil_type ?? 'Loamy' }})</option>
                                @foreach(['Loamy', 'Sandy', 'Silt', 'Clay'] as $soilOpt)
                                    <option value="{{ $soilOpt }}" {{ old('soil_type', $sample->soil_type) === $soilOpt ? 'selected' : '' }}>{{ $soilOpt }}</option>
                                @endforeach
                            </select>
                            @error('soil_type') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <div class="fert-form-actions">
                        <small>Uses SoilSense 4-sensor telemetry (pH, EC, moisture, temperature) and Open-Meteo 72h rainfall data.</small>
                        <button type="submit" class="btn btn-primary" id="recalculateFertilizerBtn">Recalculate Recommendation</button>
                    </div>
                </form>
            </details>
        @else
            <!-- Empty State when no 4-sensor telemetry exists yet -->
            <div class="fert-empty-state">
                <h3>Sensor telemetry required</h3>
                <p>No soil sensor readings (pH, EC, moisture, or temperature) are attached to this sample yet. Record 4-sensor telemetry to generate a fertilizer recommendation and 72-hour Rain Guard advisory.</p>
                @error('telemetry') <p class="field-error">{{ $message }}</p> @enderror
                <a href="{{ route('analysis') }}" class="btn btn-secondary">Record soil telemetry</a>
            </div>
        @endif

        <!-- Visible Agronomic Disclaimer -->
        <div class="agronomic-disclaimer" role="note">
            <x-icon name="warning" :size="16" />
            <span>
                <b>Agronomic Advisory Disclaimer:</b> Fertilizer recommendations are advisory only. Recommendations are generated from SoilSense 4-sensor telemetry (pH, EC, moisture, temperature) and standard crop baseline targets without laboratory N-P-K soil testing, and the ML classifier is trained on a synthetic reference dataset. Always verify fertilizer products, application rates, and timing with a local agronomist or certified soil laboratory before field application.
            </span>
        </div>
    </section>

    <!-- Parameter Interpretation & Missing Information Grid -->
    <div class="detail-grid report-details">
        <section class="card">
            <h2>Parameter interpretation</h2>
            <div class="interpret-row">
                <span class="metric-icon tone-green"><x-icon name="flask" /></span>
                <div>
                    <b>Soil pH ({{ $reading && $reading->ph !== null ? $reading->ph : 'Not measured' }})</b>
                    <p>The pH reading can be compared with crop-specific preferred ranges. It does not indicate nutrient availability by itself.</p>
                </div>
            </div>
            <div class="interpret-row">
                <span class="metric-icon tone-amber"><x-icon name="droplet" /></span>
                <div>
                    <b>Soil moisture ({{ $reading && $reading->moisture !== null ? "{$reading->moisture} {$reading->moisture_unit}" : 'Missing' }})</b>
                    <p>{{ $reading && $reading->moisture_unit === 'raw index' ? 'Raw value indicates sensor response only. Calibration against known water content is required before percentage interpretation.' : 'Calibrated volumetric water content reading.' }}</p>
                </div>
            </div>
            <div class="interpret-row">
                <span class="metric-icon tone-green"><x-icon name="temp" /></span>
                <div>
                    <b>Soil temperature ({{ $reading && $reading->temperature !== null ? "{$reading->temperature}°C" : 'Missing' }})</b>
                    <p>This timestamped reading describes conditions during testing, not seasonal root-zone temperature.</p>
                </div>
            </div>
            <div class="interpret-row">
                <span class="metric-icon tone-gray"><x-icon name="bolt" /></span>
                <div>
                    <b>Electrical conductivity ({{ $reading && $reading->ec !== null ? "{$reading->ec} {$reading->ec_unit}" : 'Pending' }})</b>
                    <p>{{ $reading && $reading->ec !== null ? "Measured EC is {$reading->ec} {$reading->ec_unit}." : 'No valid reading was received. Salinity-related limitations cannot be evaluated.' }}</p>
                </div>
            </div>
        </section>

        <aside class="card">
            <h2>Missing information</h2>
            @if($assessment && !empty($assessment->missing_information))
                @foreach($assessment->missing_information as $item)
                    <p class="missing-row"><span aria-hidden="true">-</span> {{ $item }}</p>
                @endforeach
            @else
                <p class="missing-row"><span aria-hidden="true">-</span> Laboratory nutrient analysis</p>
                <p class="missing-row"><span aria-hidden="true">-</span> Soil drainage class</p>
                <p class="missing-row"><span aria-hidden="true">-</span> Organic matter content</p>
                <p class="missing-row"><span aria-hidden="true">-</span> Calibrated volumetric moisture</p>
                <p class="missing-row"><span aria-hidden="true">-</span> Field climate and crop variety</p>
            @endif

            <div class="callout" style="margin-top: 20px;">
                <x-icon name="flask" />
                <span>
                    <b>Recommended next step</b>
                    Submit a representative sample to an accredited laboratory and consult your municipal agriculturist.
                </span>
            </div>
        </aside>
    </div>

    <!-- Data Provenance Card -->
    <section class="card provenance">
        <h2>Data provenance</h2>
        <div>
            <span><small>Source</small><b>{{ ucfirst($sample->source_type) }} ({{ $sample->testing_method }})</b></span>
            <span><small>Device reference</small><b>{{ $sample->device?->name ?? 'ESP32-01 (not connected)' }}</b></span>
            <span><small>Recorded</small><b>{{ $sample->collected_at->format('M d, Y · g:i A') }}</b></span>
            <span><small>Soil profile</small><b>{{ $sample->soil_type ?? 'Loamy (Default)' }}</b></span>
            <span><small>Calibration</small><b>{{ $phCalibration }} · {{ $moistureCalibration }}</b></span>
            <span><small>Operator</small><b>{{ $sample->operator_name ?? ($currentUser?->name ?? 'Juan Dela Cruz') }}</b></span>
        </div>
    </section>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('refreshFertilizerForm');
        const btn = document.getElementById('recalculateFertilizerBtn');
        if (form && btn) {
            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.textContent = 'Recalculating...';
            });
        }
    });
</script>
@endpush
