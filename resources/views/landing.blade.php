@extends('layouts.public')

@section('content')
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-copy">
            <span class="eyebrow">
                <span class="status-pulse" aria-hidden="true"></span>
                IoT-powered soil intelligence
            </span>
            <h1>Know Your Soil.<br /><em>Choose the Right Crops.</em></h1>
            <p>Turn sensor readings into clear soil insights, fertilizer guidance, and transparent crop recommendations, designed for farmers, students, and agricultural researchers.</p>

            <div class="hero-actions">
                <a href="{{ route('analysis') }}" class="btn btn-primary">
                    <span>Analyze soil</span>
                    <x-icon name="chevron" :size="16" />
                </a>
                <a href="#features" class="btn btn-secondary">Explore features</a>
            </div>

            <div class="hero-proof">
                <span><x-icon name="check" :size="15" /> Evidence-led guidance</span>
                <span><x-icon name="check" :size="15" /> Designed in the Philippines</span>
            </div>
        </div>

        <div class="hero-visual">
            <img src="https://images.unsplash.com/photo-1609554259810-ad331c1a9519?crop=entropy&cs=tinysrgb&fit=crop&fm=jpg&q=85&w=1400" alt="Farmer assessing an agricultural field">

            <div class="hero-panel card">
                <div class="row">
                    <div>
                        <small>Sample preview</small>
                        <b>Demo Plot A</b>
                    </div>
                    <x-badge tone="amber">Preliminary</x-badge>
                </div>
                <div class="mini-metrics">
                    <span><small>pH</small><b>6.2</b></span>
                    <span><small>Moisture</small><b>31 raw</b></span>
                    <span><small>Temp.</small><b>28°C</b></span>
                </div>
                <div class="mini-rec">
                    <span class="metric-icon tone-green"><x-icon name="sprout" /></span>
                    <span><small>Candidate crop</small><b>Tomato · Potential match</b></span>
                </div>
            </div>

            <div class="sensor-chip">
                <x-icon name="wifi" />
                <span>
                    <b>ESP32 ready</b>
                    <small>Prototype connection</small>
                </span>
            </div>
        </div>
    </section>

    <!-- Trust Strip -->
    <section class="trust-strip">
        <span>Built for transparent agricultural decisions</span>
        <div><b>4</b><small>supported sensors</small></div>
        <div><b>16</b><small>documented crop profiles</small></div>
        <div><b>100%</b><small>traceable sample data</small></div>
        <div><b>0</b><small>unsupported health claims</small></div>
    </section>

    <!-- Features Section -->
    <section class="landing-section" id="features">
        <div class="center-title">
            <span class="section-kicker">From readings to reasoning</span>
            <h2>A clearer view of your soil</h2>
            <p>Everything needed to collect measurements, understand limitations, and make better-informed next-step decisions.</p>
        </div>

        <div class="feature-grid">
            <article class="feature-card featured">
                <span class="feature-number">01</span>
                <span class="metric-icon tone-green"><x-icon name="wifi" /></span>
                <h3>Real-time monitoring</h3>
                <p>Receive pH, moisture, temperature, and EC measurements from an ESP32 device.</p>
            </article>

            <article class="feature-card">
                <span class="feature-number">02</span>
                <span class="metric-icon tone-green"><x-icon name="flask" /></span>
                <h3>Condition assessment</h3>
                <p>See exactly which readings are valid, missing, uncalibrated, or outside verified crop ranges.</p>
            </article>

            <article class="feature-card">
                <span class="feature-number">03</span>
                <span class="metric-icon tone-green"><x-icon name="sprout" /></span>
                <h3>Crop suitability</h3>
                <p>Compare available measurements with documented crop-specific requirements.</p>
            </article>

            <article class="feature-card">
                <span class="feature-number">04</span>
                <span class="metric-icon tone-green"><x-icon name="history" /></span>
                <h3>Historical reports</h3>
                <p>Track samples over time and compare conditions across plots and dates.</p>
            </article>

            <article class="feature-card">
                <span class="feature-number">05</span>
                <span class="metric-icon tone-green"><x-icon name="file" /></span>
                <h3>Actionable guidance</h3>
                <p>Understand limitations, fertilizer dosage plans, and rainfall timing before applying treatment.</p>
            </article>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-section" id="how">
        <div class="how-image">
            <img src="https://images.unsplash.com/photo-1709456808420-061f2b8488d7?crop=entropy&cs=tinysrgb&fit=crop&fm=jpg&q=80&w=1000" alt="Rice fields in the Philippines" loading="lazy">
            <span class="photo-caption">Agricultural decisions begin with reliable evidence.</span>
        </div>

        <div class="how-copy">
            <span class="section-kicker">How SoilSense works</span>
            <h2>From field sample to an explainable assessment</h2>
            <p>Every result stays connected to its source, method, calibration state, and timestamp.</p>

            <div class="steps">
                <div class="step">
                    <span>1</span>
                    <div>
                        <b>Collect a representative soil sample</b>
                        <small>Follow a consistent sampling method.</small>
                    </div>
                </div>
                <div class="step">
                    <span>2</span>
                    <div>
                        <b>ESP32 sensors capture measurements</b>
                        <small>pH, moisture, temperature, and EC.</small>
                    </div>
                </div>
                <div class="step">
                    <span>3</span>
                    <div>
                        <b>Readings are transmitted over Wi-Fi</b>
                        <small>Timestamps and device provenance included.</small>
                    </div>
                </div>
                <div class="step">
                    <span>4</span>
                    <div>
                        <b>Valid data is checked against rules</b>
                        <small>Missing or invalid data remains visible.</small>
                    </div>
                </div>
                <div class="step">
                    <span>5</span>
                    <div>
                        <b>Review conditions and crop candidates</b>
                        <small>Recommendations explain supporting and limiting factors.</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Crop Preview -->
    <section class="landing-section crop-preview">
        <div class="center-title">
            <span class="section-kicker">Transparent recommendations</span>
            <h2>See what fits, and what is still unknown</h2>
            <p>Potential matches never hide incomplete evidence. Each crop explains the measurements used and what to test next.</p>
        </div>

        <div class="crop-grid">
            @foreach($crops as $crop)
                <x-crop-card :crop="$crop" />
            @endforeach
        </div>
    </section>

    <!-- Research Disclaimer -->
    <section class="research" id="research">
        <div>
            <span class="section-kicker">Research prototype</span>
            <h2>Designed to support decisions,<br />not replace laboratory testing.</h2>
        </div>
        <div>
            <p>SoilSense is an academic IoT prototype. Recommendations depend on sensor calibration, sample quality, verified crop requirements, and the completeness of available soil information.</p>
            <p>For planting or treatment decisions, confirm findings with appropriate laboratory tests and local agricultural experts.</p>
            <a href="{{ route('dashboard') }}" class="btn btn-secondary">Explore the prototype</a>
        </div>
    </section>
@endsection
