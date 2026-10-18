@extends('layouts.app', ['title' => 'Crop Details'])

@section('content')
    <a href="{{ route('crops') }}" class="back-link">
        <x-icon name="chevron" :size="14" style="transform: rotate(180deg);" />
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
            <p style="margin-top: 8px;">{{ $crop['description'] ?? 'A widely grown crop in Philippine agro-ecological zones.' }}</p>
            
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
                    <small>Measured: {{ $crop['reading'] && $crop['reading']->ph !== null ? number_format($crop['reading']->ph, 1) : 'Not measured' }}</small>
                </div>
                <div class="comparison-bar">
                    <span></span>
                    <i style="left: {{ $crop['reading'] && $crop['reading']->ph !== null ? min(95, max(5, ($crop['reading']->ph / 14) * 100)) : 50 }}%;"></i>
                </div>
                <div>
                    <small>Requirement</small>
                    <b>{{ $crop['range'] }}</b>
                </div>
                <x-badge :tone="($crop['eval']?->ph_status === 'optimal' || $crop['eval']?->ph_status === 'tolerable') ? 'green' : (($crop['eval']?->ph_status === 'limiting') ? 'amber' : 'gray')">
                    {{ ucfirst($crop['eval']?->ph_status ?? 'Unknown') }}
                </x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Temperature</b>
                    <small>Measured: {{ $crop['reading'] && $crop['reading']->temperature !== null ? round($crop['reading']->temperature) . '°C' : 'Not measured' }}</small>
                </div>
                <div class="comparison-bar">
                    <span></span>
                    <i style="left: {{ $crop['reading'] && $crop['reading']->temperature !== null ? min(95, max(5, ($crop['reading']->temperature / 50) * 100)) : 50 }}%;"></i>
                </div>
                <div>
                    <small>Requirement</small>
                    <b>{{ $crop['requirements']?->min_temperature ?? 15 }}–{{ $crop['requirements']?->max_temperature ?? 30 }}°C</b>
                </div>
                <x-badge :tone="($crop['eval']?->temperature_status === 'optimal' || $crop['eval']?->temperature_status === 'tolerable') ? 'green' : (($crop['eval']?->temperature_status === 'limiting') ? 'amber' : 'gray')">
                    {{ ucfirst($crop['eval']?->temperature_status ?? 'Unknown') }}
                </x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Soil moisture</b>
                    <small>Measured: {{ $crop['reading'] && $crop['reading']->moisture !== null ? $crop['reading']->moisture . ' ' . $crop['reading']->moisture_unit : 'Missing' }}</small>
                </div>
                <div class="comparison-bar">
                    <span style="left: 10%; right: 40%;"></span>
                    <i style="left: {{ $crop['reading'] && $crop['reading']->moisture !== null ? min(95, max(5, $crop['reading']->moisture)) : 20 }}%;"></i>
                </div>
                <div>
                    <small>Requirement</small>
                    <b>{{ $crop['requirements']?->min_moisture ? $crop['requirements']->min_moisture . '–' . $crop['requirements']->max_moisture . '%' : 'Calibrated value needed' }}</b>
                </div>
                <x-badge :tone="$crop['eval']?->moisture_status === 'uncalibrated' ? 'amber' : (($crop['eval']?->moisture_status === 'optimal' || $crop['eval']?->moisture_status === 'tolerable') ? 'green' : 'gray')">
                    {{ ucfirst($crop['eval']?->moisture_status ?? 'Unknown') }}
                </x-badge>
            </div>

            <div class="comparison-row">
                <div>
                    <b>Electrical conductivity</b>
                    <small>Measured: {{ $crop['reading'] && $crop['reading']->ec !== null ? $crop['reading']->ec . ' ' . $crop['reading']->ec_unit : 'Not measured' }}</small>
                </div>
                <div class="comparison-bar">
                    <span style="opacity: 0.3;"></span>
                    <i style="left: {{ $crop['reading'] && $crop['reading']->ec !== null ? min(95, max(5, ($crop['reading']->ec / 5) * 100)) : 10 }}%;"></i>
                </div>
                <div>
                    <small>Salinity limit</small>
                    <b><= {{ $crop['requirements']?->max_ec ?? '2.5' }} {{ $crop['requirements']?->ec_unit ?? 'mS/cm' }}</b>
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
                    <p>{{ $crop['next_steps'] ?? 'Submit samples to an accredited laboratory for N-P-K chemical test; calibrate probe and confirm local variety needs.' }}</p>
                </span>
            </div>
        </aside>
    </div>

    <!-- References & Sources Card -->
    <section class="card references" style="display: block;">
        <h2>Documented requirements and references</h2>
        <p style="margin: 8px 0 14px;"><b>Primary Citation:</b> {{ $crop['requirements']->source_citation ?? 'FAO Irrigation and Drainage Paper 29 & DA-BAR Compendium' }}</p>
        @if(!empty($crop['requirements']->applicability_notes))
            <p style="margin-bottom: 6px;"><b>Applicability:</b> {{ $crop['requirements']->applicability_notes }}</p>
        @endif
        @if(!empty($crop['requirements']->limitations_notes))
            <p style="margin-bottom: 16px; color: var(--amber);"><b>Agronomic Limitations:</b> {{ $crop['requirements']->limitations_notes }}</p>
        @endif
        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <a href="{{ route('crops') }}" class="btn btn-secondary">Explore all crops</a>
            <a href="{{ route('analysis') }}" class="btn btn-primary">Test another sample</a>
        </div>
    </section>
@endsection
