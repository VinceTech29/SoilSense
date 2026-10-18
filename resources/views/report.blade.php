@extends('layouts.app', ['title' => 'Assessment Report'])

@section('content')
    <div class="report-head">
        <div>
            <a href="{{ route('dashboard') }}" class="back-link">
                <x-icon name="chevron" :size="14" style="transform: rotate(180deg);" />
                <span>Back to overview</span>
            </a>
            <span class="section-kicker">Soil assessment report</span>
            <h1>Sample {{ $sample->sample_code }} — {{ $sample->name }}</h1>
            <p>Collected {{ $sample->collected_at->format('M d, Y · g:i A') }} · {{ $sample->location ?? 'Unspecified Location' }} · {{ ucfirst($sample->source_type) }} data</p>
        </div>
        <div>
            <button type="button" class="btn btn-secondary" onclick="alert('PDF export prepared for academic review.')">
                <x-icon name="download" :size="16" />
                <span>Export PDF</span>
            </button>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print report</button>
        </div>
    </div>

    <!-- Preliminary Warning Notice -->
    <div class="notice demo">
        <x-icon name="warning" />
        <div>
            <b>Preliminary prototype report</b>
            <span>This report uses illustrative data. It is not a laboratory certificate and should not be used as the sole basis for agricultural treatment or planting decisions.</span>
        </div>
    </div>

    <!-- Executive Summary Card -->
    <section class="card report-summary">
        <div>
            <x-badge :tone="($assessment?->status ?? 'preliminary') === 'reviewed' ? 'green' : 'amber'">
                {{ ucfirst($assessment?->status ?? 'Incomplete evidence') }}
            </x-badge>
            <h2>Executive summary</h2>
            <p>{{ $assessment?->summary ?? 'The available pH and temperature readings can support an initial crop-range comparison. Soil moisture cannot be interpreted as a percentage because the sensor is uncalibrated, and EC is not available. No universal soil health conclusion is made.' }}</p>
        </div>
        <div class="report-complete">
            <span>Assessment completeness</span>
            <b>{{ $assessment?->usable_parameters_count ?? 2 }} of 4 usable</b>
            <div>
                <i style="width: {{ (($assessment?->usable_parameters_count ?? 2) / 4) * 100 }}%;"></i>
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
                <p>These candidates match the valid pH and temperature readings. They are not guaranteed planting outcomes because EC, calibrated moisture, nutrients, texture, and drainage are still unknown.</p>
            </div>
            <a href="{{ route('crops') }}" class="btn btn-secondary">Explore all crop matches</a>
        </div>

        <div class="result-crop-grid">
            @forelse(array_slice($crops, 0, 3) as $index => $crop)
                <article class="card result-crop">
                    <img src="{{ $crop['image'] }}" alt="{{ $crop['name'] }} crop">
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
                                <b>Evidence is incomplete</b>
                                {{ $crop['limit'] }}. Verify these before planting.
                            </span>
                        </div>

                        <a href="{{ route('crop.detail', ['name' => strtolower($crop['name'])]) }}" class="btn btn-secondary">
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
                <p>{{ $assessment?->recommended_next_steps ?? 'Calibrate the moisture sensor and test EC, nutrients, texture, organic matter, and drainage. Confirm the crop variety and local planting calendar with your municipal agriculturist.' }}</p>
            </div>
            <a href="{{ route('analysis') }}" class="btn btn-primary">Run another soil test</a>
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
                    <p>The raw value indicates sensor response only. Calibration against known water content is required before percentage interpretation.</p>
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
                    <p class="missing-row"><span>—</span> {{ $item }}</p>
                @endforeach
            @else
                <p class="missing-row"><span>—</span> Macronutrient and micronutrient analysis</p>
                <p class="missing-row"><span>—</span> Soil texture and drainage class</p>
                <p class="missing-row"><span>—</span> Organic matter content</p>
                <p class="missing-row"><span>—</span> Calibrated volumetric moisture</p>
                <p class="missing-row"><span>—</span> Field climate and crop variety</p>
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
            <span><small>Method</small><b>{{ $sample->testing_method }}</b></span>
            <span><small>Calibration</small><b>pH: recorded · Moisture: uncalibrated</b></span>
            <span><small>Operator</small><b>{{ $sample->operator_name ?? 'Juan Dela Cruz' }}</b></span>
        </div>
    </section>
@endsection
