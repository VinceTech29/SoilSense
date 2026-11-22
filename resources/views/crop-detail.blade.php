@extends('layouts.app', ['title' => 'Crop Details'])

@section('content')
    @php
        $req = $crop['requirements'];
        $reading = $crop['reading'];

        // Soil pH scale: 0 to 14
        $phMin = $req?->min_ph !== null ? (float) $req->min_ph : null;
        $phMax = $req?->max_ph !== null ? (float) $req->max_ph : null;
        $phLeft = $phMin !== null ? max(0, min(100, ($phMin / 14) * 100)) : 0;
        $phRight = $phMax !== null ? max(0, min(100, 100 - (($phMax / 14) * 100))) : 100;
        $phPin = $reading && $reading->ph !== null ? max(3, min(97, ($reading->ph / 14) * 100)) : null;

        // Temperature scale: 0 to 50 C
        $tempMin = $req?->min_temperature !== null ? (float) $req->min_temperature : null;
        $tempMax = $req?->max_temperature !== null ? (float) $req->max_temperature : null;
        $tempLeft = $tempMin !== null ? max(0, min(100, ($tempMin / 50) * 100)) : 0;
        $tempRight = $tempMax !== null ? max(0, min(100, 100 - (($tempMax / 50) * 100))) : 100;
        $tempPin = $reading && $reading->temperature !== null ? max(3, min(97, ($reading->temperature / 50) * 100)) : null;

        // Moisture scale: 0 to 100 %
        $moistMin = $req?->min_moisture !== null ? (float) $req->min_moisture : null;
        $moistMax = $req?->max_moisture !== null ? (float) $req->max_moisture : null;
        $moistLeft = $moistMin !== null ? max(0, min(100, $moistMin)) : 0;
        $moistRight = $moistMax !== null ? max(0, min(100, 100 - $moistMax)) : 100;
        $moistCalibrated = $reading && $reading->moisture !== null && $reading->moisture_unit !== 'raw index';
        $moistPin = $moistCalibrated ? max(3, min(97, (float) $reading->moisture)) : null;

        // EC scale: 0 to 5 mS/cm
        $ecMax = $req?->max_ec !== null ? (float) $req->max_ec : null;
        $ecRight = $ecMax !== null ? max(0, min(100, 100 - (($ecMax / 5) * 100))) : 100;
        $ecPin = $reading && $reading->ec !== null ? max(3, min(97, ($reading->ec / 5) * 100)) : null;
    @endphp

    <a href="{{ route('crops') }}" class="back-link">
        <x-icon name="chevron" :size="14" class="icon-back-flip" />
        <span>Back to crop recommendations</span>
    </a>

    <!-- Crop Hero Header -->
    <section class="crop-hero card">
        <img src="{{ $crop['image'] }}" alt="{{ $crop['name'] }} growing on a farm">
        <div>
            <x-badge :tone="$crop['tone']">{{ $crop['status'] }}</x-badge>
            <span class="section-kicker" style="margin-top: 10px;">Crop suitability profile</span>
            <h1>{{ $crop['name'] }}</h1>
            <i>{{ $crop['scientific'] }}</i>
            <p>{{ $crop['description'] ?? 'A widely grown crop in Philippine agro-ecological zones.' }}</p>

            <div class="crop-hero-stats">
                <span>
                    <small>Match score</small>
                    <b>{{ $crop['score'] !== null ? $crop['score'] . '%' : 'N/A' }}</b>
                </span>
                <span>
                    <small>Evidence completeness</small>
                    <b>{{ $crop['confidence'] ?? 'Preliminary' }}</b>
                </span>
                <span>
                    <small>Suitability tier</small>
                    <b>{{ $crop['status'] }}</b>
                </span>
            </div>
        </div>
    </section>

    <!-- Detail Comparison & System Interpretation Grid -->
    <div class="detail-grid">
        <section class="card">
            <div class="card-header">
                <div>
                    <span class="section-kicker">Requirement comparison</span>
                    <h2>Your sample vs. {{ strtolower($crop['name']) }} needs</h2>
                </div>
                <x-badge :tone="$crop['tone']">{{ $crop['status'] }}</x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Soil pH</b>
                    <small>Measured: {{ $reading && $reading->ph !== null ? number_format($reading->ph, 1) : 'Not measured' }}</small>
                </div>
                <div class="comparison-bar" aria-label="Soil pH range bar">
                    @if($phMin !== null && $phMax !== null)
                        <span style="left: {{ round($phLeft, 1) }}%; right: {{ round($phRight, 1) }}%;"></span>
                    @endif
                    @if($phPin !== null)
                        <i style="left: {{ round($phPin, 1) }}%;"></i>
                    @endif
                </div>
                <div>
                    <small>Requirement (0–14)</small>
                    <b>{{ $crop['range'] }}</b>
                </div>
                <x-badge :tone="($crop['eval']?->ph_status === 'optimal' || $crop['eval']?->ph_status === 'tolerable') ? 'green' : (($crop['eval']?->ph_status === 'limiting') ? 'amber' : 'gray')">
                    {{ ucfirst($crop['eval']?->ph_status ?? 'Unknown') }}
                </x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Temperature</b>
                    <small>Measured: {{ $reading && $reading->temperature !== null ? round($reading->temperature) . '°C' : 'Not measured' }}</small>
                </div>
                <div class="comparison-bar" aria-label="Temperature range bar">
                    @if($tempMin !== null && $tempMax !== null)
                        <span style="left: {{ round($tempLeft, 1) }}%; right: {{ round($tempRight, 1) }}%;"></span>
                    @endif
                    @if($tempPin !== null)
                        <i style="left: {{ round($tempPin, 1) }}%;"></i>
                    @endif
                </div>
                <div>
                    <small>Requirement (0–50°C)</small>
                    <b>{{ $req?->min_temperature ?? 15 }}–{{ $req?->max_temperature ?? 30 }}°C</b>
                </div>
                <x-badge :tone="($crop['eval']?->temperature_status === 'optimal' || $crop['eval']?->temperature_status === 'tolerable') ? 'green' : (($crop['eval']?->temperature_status === 'limiting') ? 'amber' : 'gray')">
                    {{ ucfirst($crop['eval']?->temperature_status ?? 'Unknown') }}
                </x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Soil moisture</b>
                    <small>Measured: {{ $reading && $reading->moisture !== null ? $reading->moisture . ' ' . $reading->moisture_unit : 'Missing' }}</small>
                </div>
                <div class="comparison-bar" aria-label="Soil moisture range bar">
                    @if($moistMin !== null && $moistMax !== null)
                        <span style="left: {{ round($moistLeft, 1) }}%; right: {{ round($moistRight, 1) }}%;"></span>
                    @endif
                    @if($moistPin !== null)
                        <i style="left: {{ round($moistPin, 1) }}%;"></i>
                    @endif
                </div>
                <div>
                    <small>Requirement (% VWC)</small>
                    <b>{{ $req?->min_moisture ? $req->min_moisture . '–' . $req->max_moisture . '%' : 'Calibrated value needed' }}</b>
                </div>
                <x-badge :tone="$crop['eval']?->moisture_status === 'uncalibrated' ? 'amber' : (($crop['eval']?->moisture_status === 'optimal' || $crop['eval']?->moisture_status === 'tolerable') ? 'green' : 'gray')">
                    {{ ucfirst($crop['eval']?->moisture_status ?? 'Unknown') }}
                </x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Electrical conductivity</b>
                    <small>Measured: {{ $reading && $reading->ec !== null ? $reading->ec . ' ' . $reading->ec_unit : 'Not measured' }}</small>
                </div>
                <div class="comparison-bar" aria-label="Electrical conductivity tolerance bar">
                    @if($ecMax !== null)
                        <span style="left: 0%; right: {{ round($ecRight, 1) }}%;"></span>
                    @endif
                    @if($ecPin !== null)
                        <i style="left: {{ round($ecPin, 1) }}%;"></i>
                    @endif
                </div>
                <div>
                    <small>Salinity limit (0–5 mS/cm)</small>
                    <b>&le; {{ $req?->max_ec ?? '2.5' }} {{ $req?->ec_unit ?? 'mS/cm' }}</b>
                </div>
                <x-badge :tone="$crop['eval']?->ec_status === 'tolerable' ? 'green' : (($crop['eval']?->ec_status === 'limiting') ? 'red' : 'gray')">
                    {{ ucfirst($crop['eval']?->ec_status ?? 'Missing') }}
                </x-badge>
            </div>
        </section>

        <aside class="card interpretation">
            <span class="section-kicker">System interpretation</span>
            <h2>Why this is a {{ strtolower($crop['status']) }}</h2>

            <div class="reason support">
                <x-icon name="check" />
                <span>
                    <b>Supporting factors</b>
                    <p>{{ $crop['support'] }}</p>
                </span>
            </div>

            <div class="reason limit">
                <x-icon name="warning" />
                <span>
                    <b>Potential limitations</b>
                    <p>{{ $crop['limit'] }}</p>
                </span>
            </div>

            <div class="reason missing">
                <x-icon name="flask" />
                <span>
                    <b>Recommended follow-up</b>
                    <p>{{ $crop['next_steps'] ?? 'Confirm sensor calibration and consult local agronomic guidelines before planting.' }}</p>
                </span>
            </div>
        </aside>
    </div>

    <!-- References & Sources Card -->
    <section class="card references">
        <h2>Documented requirements and references</h2>
        <p><b>Primary Citation:</b> {{ $req->source_citation ?? 'FAO Irrigation and Drainage Paper 29 & DA-BAR Compendium' }}</p>
        @if(!empty($req->applicability_notes))
            <p><b>Applicability:</b> {{ $req->applicability_notes }}</p>
        @endif
        @if(!empty($req->limitations_notes))
            <p class="references-note-warn"><b>Agronomic Limitations:</b> {{ $req->limitations_notes }}</p>
        @endif
        <div class="references-actions">
            <a href="{{ route('crops') }}" class="btn btn-secondary">Explore all crops</a>
            <a href="{{ route('analysis') }}" class="btn btn-primary">Test another sample</a>
        </div>
    </section>
@endsection
