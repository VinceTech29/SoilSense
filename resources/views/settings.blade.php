@extends('layouts.app', ['title' => 'Settings'])

@section('content')
    @php
        $currentTab = $activeTab ?? 'profile';
        $deviceStatus = $device?->status ?? 'offline';
        $deviceTone = $deviceStatus === 'online' ? 'green' : ($deviceStatus === 'error' ? 'red' : 'gray');
    @endphp

    <div class="page-title">
        <div>
            <h1>Settings</h1>
            <p>Manage user profile, sensor measurement units, agronomic metadata, and data exports.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="notice success" role="status">
            <x-icon name="check" :size="20" />
            <div>
                <b>Success</b>
                <span>{{ session('success') }}</span>
            </div>
        </div>
    @endif

    @if($errors->any())
        <div class="notice error" role="alert">
            <x-icon name="alert" :size="20" />
            <div>
                <b>Please correct the following:</b>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <!-- Settings Navigation & Form Grid -->
    <div class="settings-grid">
        <!-- Settings Category Nav -->
        <nav class="card settings-nav" id="settingsNav" role="tablist" aria-orientation="vertical" aria-label="Settings categories">
            <button type="button" role="tab" id="tab-profile" aria-controls="panel-profile" aria-selected="{{ $currentTab === 'profile' ? 'true' : 'false' }}" class="{{ $currentTab === 'profile' ? 'active' : '' }}" data-tab="profile">
                <span>User profile</span>
                <x-icon name="chevron" :size="15" />
            </button>
            <button type="button" role="tab" id="tab-units" aria-controls="panel-units" aria-selected="{{ $currentTab === 'units' ? 'true' : 'false' }}" class="{{ $currentTab === 'units' ? 'active' : '' }}" data-tab="units">
                <span>Measurement units</span>
                <x-icon name="chevron" :size="15" />
            </button>
            <button type="button" role="tab" id="tab-metadata" aria-controls="panel-metadata" aria-selected="{{ $currentTab === 'metadata' ? 'true' : 'false' }}" class="{{ $currentTab === 'metadata' ? 'active' : '' }}" data-tab="metadata">
                <span>Sample metadata</span>
                <x-icon name="chevron" :size="15" />
            </button>
            <button type="button" role="tab" id="tab-crops" aria-controls="panel-crops" aria-selected="{{ $currentTab === 'crops' ? 'true' : 'false' }}" class="{{ $currentTab === 'crops' ? 'active' : '' }}" data-tab="crops">
                <span>Crop recommendations</span>
                <x-icon name="chevron" :size="15" />
            </button>
            <button type="button" role="tab" id="tab-exports" aria-controls="panel-exports" aria-selected="{{ $currentTab === 'exports' ? 'true' : 'false' }}" class="{{ $currentTab === 'exports' ? 'active' : '' }}" data-tab="exports">
                <span>Data exports</span>
                <x-icon name="chevron" :size="15" />
            </button>
            <button type="button" role="tab" id="tab-device" aria-controls="panel-device" aria-selected="{{ $currentTab === 'device' ? 'true' : 'false' }}" class="{{ $currentTab === 'device' ? 'active' : '' }}" data-tab="device">
                <span>Device configuration</span>
                <x-icon name="chevron" :size="15" />
            </button>
            <button type="button" role="tab" id="tab-about" aria-controls="panel-about" aria-selected="{{ $currentTab === 'about' ? 'true' : 'false' }}" class="{{ $currentTab === 'about' ? 'active' : '' }}" data-tab="about">
                <span>About the project</span>
                <x-icon name="chevron" :size="15" />
            </button>
        </nav>

        <!-- Right Side Tab Panels Container -->
        <div class="settings-content">
            <!-- TAB 1: User Profile Form -->
            <section class="card settings-form tab-panel" id="panel-profile" role="tabpanel" aria-labelledby="tab-profile" @if($currentTab !== 'profile') hidden @endif>
                <span class="section-kicker">Account settings</span>
                <h2>User profile</h2>
                <p>Used for sample provenance and generated report attribution in research records.</p>

                <!-- Profile Header & Avatar Card -->
                <div class="profile-edit">
                    <div id="avatarPreviewContainer" style="flex-shrink: 0;">
                        @if($user->avatar_url)
                            <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" class="avatar large avatar-img" id="avatarPreviewImg">
                        @else
                            <span class="avatar large" id="avatarPreviewInitials">{{ $user->initials }}</span>
                        @endif
                    </div>
                    <div class="profile-meta">
                        <b id="displayUserName">{{ $user->name }}</b>
                        <small id="displayUserRole">{{ $user->role }}</small>
                        <small>{{ $user->organization }} · {{ $user->region }}</small>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('avatarFileInput').click()">
                        <x-icon name="camera" :size="15" />
                        <span>Change photo</span>
                    </button>
                </div>

                <form action="{{ route('settings.profile') }}" method="POST" enctype="multipart/form-data" id="profileForm">
                    @csrf
                    <input type="file" name="avatar" id="avatarFileInput" accept="image/*" hidden>

                    <div class="form-grid">
                        <label>
                            <span>Full name <span class="required-mark">*</span></span>
                            <input type="text" name="name" id="inputName" value="{{ old('name', $user->name) }}" required>
                        </label>

                        <label>
                            <span>Role / Title <span class="required-mark">*</span></span>
                            <select name="role" id="inputRole" required>
                                @php
                                    $roles = ['Agricultural Researcher', 'Agronomist', 'Farmer', 'Soil Scientist', 'Student', 'Laboratory Technician', 'Extension Officer'];
                                    $currentRole = old('role', $user->role);
                                @endphp
                                @foreach($roles as $r)
                                    <option value="{{ $r }}" {{ $currentRole === $r ? 'selected' : '' }}>{{ $r }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>Organization / Institution</span>
                            <input type="text" name="organization" value="{{ old('organization', $user->organization) }}" placeholder="e.g. University Research Farm">
                        </label>

                        <label>
                            <span>Agricultural Region <span class="required-mark">*</span></span>
                            <select name="region" required>
                                @php
                                    $regions = [
                                        'CALABARZON', 'Central Luzon', 'Ilocos Region', 'Cagayan Valley',
                                        'Bicol Region', 'Western Visayas', 'Central Visayas', 'Eastern Visayas',
                                        'Davao Region', 'Northern Mindanao', 'SOCCSKSARGEN', 'Caraga',
                                        'BARMM', 'Cordillera Administrative Region (CAR)', 'National Capital Region (NCR)'
                                    ];
                                    $currentRegion = old('region', $user->region);
                                @endphp
                                @foreach($regions as $reg)
                                    <option value="{{ $reg }}" {{ $currentRegion === $reg ? 'selected' : '' }}>{{ $reg }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span>Email address <span class="required-mark">*</span></span>
                            <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
                        </label>

                        <label>
                            <span>Contact Phone</span>
                            <input type="tel" name="phone" value="{{ old('phone', $user->phone ?? '+63 917 123 4567') }}" placeholder="+63 9XX XXX XXXX">
                        </label>

                        <label class="full">
                            <span>Profile Image URL (Optional external avatar)</span>
                            <input type="url" name="avatar_url" id="inputAvatarUrl" value="{{ old('avatar_url', $user->avatar_url) }}" placeholder="https://images.unsplash.com/... or uploaded photo">
                        </label>
                    </div>

                    <div class="settings-actions">
                        <button type="reset" class="btn btn-ghost">Reset</button>
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check" :size="16" />
                            <span>Save profile changes</span>
                        </button>
                    </div>
                </form>

                <!-- Password and Security Sub-section -->
                <div class="settings-subsection">
                    <span class="section-kicker">Security credentials</span>
                    <h3>Change account password</h3>
                    <p>Update your password to keep research access and data management secure.</p>

                    <form action="{{ route('settings.security') }}" method="POST">
                        @csrf
                        <div class="form-grid">
                            <label>
                                <span>Current password <span class="required-mark">*</span></span>
                                <input type="password" name="current_password" required placeholder="Enter current password">
                            </label>

                            <label>
                                <span>New password <span class="required-mark">*</span></span>
                                <input type="password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
                            </label>

                            <label class="full">
                                <span>Confirm new password <span class="required-mark">*</span></span>
                                <input type="password" name="new_password_confirmation" required minlength="8" placeholder="Re-type new password">
                            </label>
                        </div>

                        <div class="settings-actions">
                            <button type="submit" class="btn btn-secondary">
                                <x-icon name="lock" :size="15" />
                                <span>Update password</span>
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- TAB 2: Measurement Units -->
            <section class="card settings-form tab-panel" id="panel-units" role="tabpanel" aria-labelledby="tab-units" @if($currentTab !== 'units') hidden @endif>
                <span class="section-kicker">Sensor telemetry</span>
                <h2>Measurement units &amp; precision</h2>
                <p>Configure how raw sensor readings are formatted, converted, and displayed across dashboard metrics and reports.</p>

                <form action="{{ route('settings.preferences') }}" method="POST">
                    @csrf
                    <input type="hidden" name="tab" value="units">

                    <div class="form-grid">
                        <label>
                            <span>Temperature unit</span>
                            <select name="temp_unit">
                                <option value="C" {{ $user->getPreference('temp_unit', 'C') === 'C' ? 'selected' : '' }}>Celsius (°C) - Default</option>
                                <option value="F" {{ $user->getPreference('temp_unit', 'C') === 'F' ? 'selected' : '' }}>Fahrenheit (°F)</option>
                            </select>
                        </label>

                        <label>
                            <span>Moisture measurement display</span>
                            <select name="moisture_unit">
                                <option value="% VWC" {{ $user->getPreference('moisture_unit', '% VWC') === '% VWC' ? 'selected' : '' }}>Calibrated volumetric water content (% VWC)</option>
                                <option value="raw index" {{ $user->getPreference('moisture_unit', '% VWC') === 'raw index' ? 'selected' : '' }}>Raw analog index (Uncalibrated ADC)</option>
                            </select>
                        </label>

                        <label>
                            <span>Electrical conductivity (EC) unit</span>
                            <select name="ec_unit">
                                <option value="mS/cm" {{ $user->getPreference('ec_unit', 'mS/cm') === 'mS/cm' ? 'selected' : '' }}>Millisiemens per centimeter (mS/cm)</option>
                                <option value="uS/cm" {{ $user->getPreference('ec_unit', 'mS/cm') === 'uS/cm' ? 'selected' : '' }}>Microsiemens per centimeter (µS/cm)</option>
                            </select>
                        </label>

                        <label>
                            <span>Soil pH decimal precision</span>
                            <select name="ph_precision">
                                <option value="1" {{ $user->getPreference('ph_precision', '1') === '1' ? 'selected' : '' }}>1 decimal place (e.g. 6.4)</option>
                                <option value="2" {{ $user->getPreference('ph_precision', '1') === '2' ? 'selected' : '' }}>2 decimal places (e.g. 6.42)</option>
                            </select>
                        </label>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check" :size="16" />
                            <span>Save measurement units</span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- TAB 3: Sample Metadata -->
            <section class="card settings-form tab-panel" id="panel-metadata" role="tabpanel" aria-labelledby="tab-metadata" @if($currentTab !== 'metadata') hidden @endif>
                <span class="section-kicker">Field operations</span>
                <h2>Sample metadata &amp; logging defaults</h2>
                <p>Pre-populate sample creation forms and analysis screens to speed up field operations.</p>

                <form action="{{ route('settings.preferences') }}" method="POST">
                    @csrf
                    <input type="hidden" name="tab" value="metadata">
                    <input type="hidden" name="sample_metadata_submitted" value="1">

                    <div class="form-grid">
                        <label>
                            <span>Default sample code prefix</span>
                            <input type="text" name="sample_prefix" value="{{ $user->getPreference('sample_prefix', 'SS') }}" placeholder="e.g. SS">
                        </label>

                        <label>
                            <span>Default testing method</span>
                            <select name="default_testing_method">
                                @php
                                    $methods = ['Sensor Probe + DA Lab', 'Rapid Field Probe', 'IoT Continuous Telemetry', 'University Greenhouse'];
                                    $curMethod = $user->getPreference('default_testing_method', 'Sensor Probe + DA Lab');
                                @endphp
                                @foreach($methods as $m)
                                    <option value="{{ $m }}" {{ $curMethod === $m ? 'selected' : '' }}>{{ $m }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="full">
                            <span>Default collection location</span>
                            <input type="text" name="default_location" value="{{ $user->getPreference('default_location', 'Demo Farm, Laguna') }}" placeholder="e.g. Demo Farm, Laguna">
                        </label>

                        <div class="full checkbox-row">
                            <input type="checkbox" id="autoTimestamp" name="auto_timestamp" value="1" {{ $user->getPreference('auto_timestamp', true) ? 'checked' : '' }}>
                            <label for="autoTimestamp">
                                Automatically timestamp newly registered soil tests with current local time
                            </label>
                        </div>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check" :size="16" />
                            <span>Save sample defaults</span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- TAB 4: Crop Recommendations -->
            <section class="card settings-form tab-panel" id="panel-crops" role="tabpanel" aria-labelledby="tab-crops" @if($currentTab !== 'crops') hidden @endif>
                <span class="section-kicker">Agronomic engine</span>
                <h2>Recommendation engine rules</h2>
                <p>Adjust suitability classification sensitivity and missing parameter handling criteria.</p>

                <form action="{{ route('settings.preferences') }}" method="POST">
                    @csrf
                    <input type="hidden" name="tab" value="crops">

                    <div class="form-grid">
                        <label class="full">
                            <span>Recommendation engine sensitivity</span>
                            <select name="recommendation_tier">
                                <option value="balanced" {{ $user->getPreference('recommendation_tier', 'balanced') === 'balanced' ? 'selected' : '' }}>
                                    Balanced (Standard agronomic intervals with transparent limiting factors) - Recommended
                                </option>
                                <option value="strict" {{ $user->getPreference('recommendation_tier', 'balanced') === 'strict' ? 'selected' : '' }}>
                                    Strict / Research Grade (Requires optimal pH and calibrated moisture for Match)
                                </option>
                                <option value="permissive" {{ $user->getPreference('recommendation_tier', 'balanced') === 'permissive' ? 'selected' : '' }}>
                                    Permissive (Highlights potential match even when marginal readings are present)
                                </option>
                            </select>
                        </label>

                        <label class="full">
                            <span>Missing data handling policy</span>
                            <select name="missing_data_policy">
                                <option value="flag_uncalibrated" {{ $user->getPreference('missing_data_policy', 'flag_uncalibrated') === 'flag_uncalibrated' ? 'selected' : '' }}>
                                    Flag uncalibrated readings (Withhold numeric score for uncalibrated ADC or missing EC) - Recommended
                                </option>
                                <option value="require_all" {{ $user->getPreference('missing_data_policy', 'flag_uncalibrated') === 'require_all' ? 'selected' : '' }}>
                                    Require all 4 sensor channels (Strict validation before generating suitability)
                                </option>
                                <option value="allow_partial" {{ $user->getPreference('missing_data_policy', 'flag_uncalibrated') === 'allow_partial' ? 'selected' : '' }}>
                                    Allow partial evaluation (Assess on pH alone if others unavailable)
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="settings-actions">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check" :size="16" />
                            <span>Save engine rules</span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- TAB 5: Data Exports -->
            <section class="card settings-form tab-panel" id="panel-exports" role="tabpanel" aria-labelledby="tab-exports" @if($currentTab !== 'exports') hidden @endif>
                <span class="section-kicker">Data portability</span>
                <h2>Data exports &amp; research records</h2>
                <p>Download full database records, sensor telemetry, fertilizer advisories, and crop requirements in open UTF-8 CSV format.</p>

                <div class="export-grid">
                    <div class="export-card">
                        <div>
                            <span class="metric-icon tone-green" style="margin-bottom: 12px;"><x-icon name="file" :size="20" /></span>
                            <h3>Soil Samples &amp; Telemetry Log</h3>
                            <p>Export all registered soil samples, timestamps, 4-sensor measurements, fertilizer recommendations, and Rain Guard advisories.</p>
                        </div>
                        <a href="{{ route('settings.export.samples') }}" class="btn btn-primary btn-block">
                            <x-icon name="download" :size="16" />
                            <span>Download Samples CSV</span>
                        </a>
                    </div>

                    <div class="export-card">
                        <div>
                            <span class="metric-icon tone-blue" style="margin-bottom: 12px;"><x-icon name="sprout" :size="20" /></span>
                            <h3>Crop Agronomic Reference Database</h3>
                            <p>Export all 16 crop profiles, scientific names, documented pH ranges, moisture tolerances, temperature brackets, and Philippine DA/FAO citations.</p>
                        </div>
                        <a href="{{ route('settings.export.crops') }}" class="btn btn-secondary btn-block">
                            <x-icon name="download" :size="16" />
                            <span>Download Crops CSV</span>
                        </a>
                    </div>
                </div>
            </section>

            <!-- TAB 6: Device Configuration -->
            <section class="card settings-form tab-panel" id="panel-device" role="tabpanel" aria-labelledby="tab-device" @if($currentTab !== 'device') hidden @endif>
                <span class="section-kicker">IoT Hardware node</span>
                <h2>ESP32 Device node settings</h2>
                <p>Manage hardware telemetry sync intervals and check ingestion API configuration.</p>

                <div class="settings-device-banner">
                    <div class="device-icon">
                        <x-icon name="cpu" :size="22" />
                    </div>
                    <div>
                        <div class="row">
                            <b style="font-size: 15px;">{{ $device?->display_name ?? 'ESP32 Soil Node 01' }}</b>
                            <x-badge :tone="$deviceTone">{{ ucfirst($deviceStatus) }}</x-badge>
                        </div>
                        <small>UID: {{ $device?->device_uid ?? 'ESP32-01' }} · Auth: Device Token Hash</small>
                    </div>
                </div>

                <form action="{{ route('settings.preferences') }}" method="POST">
                    @csrf
                    <input type="hidden" name="tab" value="device">

                    <div class="form-grid">
                        <label>
                            <span>Telemetry reporting interval</span>
                            <select name="sync_interval_seconds">
                                <option value="15" {{ $user->getPreference('sync_interval_seconds', 60) == 15 ? 'selected' : '' }}>15 seconds (High frequency test)</option>
                                <option value="30" {{ $user->getPreference('sync_interval_seconds', 60) == 30 ? 'selected' : '' }}>30 seconds (Standard lab test)</option>
                                <option value="60" {{ $user->getPreference('sync_interval_seconds', 60) == 60 ? 'selected' : '' }}>60 seconds (Recommended field sync)</option>
                                <option value="300" {{ $user->getPreference('sync_interval_seconds', 60) == 300 ? 'selected' : '' }}>5 minutes (Battery saving)</option>
                            </select>
                        </label>

                        <label>
                            <span>Linked IoT Device</span>
                            <input type="text" value="{{ $device?->device_uid ?? 'ESP32-01' }} ({{ ucfirst($deviceStatus) }})" readonly class="input-readonly">
                        </label>

                        <label class="full">
                            <span>API Ingestion Endpoint (POST)</span>
                            <div class="inline-endpoint">
                                <input type="text" id="apiEndpointUrl" value="{{ url('/api/v1/readings') }}" readonly>
                                <button type="button" class="btn btn-secondary" onclick="copyEndpointUrl()" id="copyUrlBtn">
                                    <x-icon name="clipboard" :size="15" />
                                    <span>Copy URL</span>
                                </button>
                            </div>
                        </label>
                    </div>

                    <div class="settings-actions split">
                        <a href="{{ route('device') }}" class="btn btn-secondary">
                            <x-icon name="sliders" :size="15" />
                            <span>Open Telemetry Simulator &amp; Monitor</span>
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check" :size="16" />
                            <span>Save device settings</span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- TAB 7: About the Project -->
            <section class="card settings-form tab-panel" id="panel-about" role="tabpanel" aria-labelledby="tab-about" @if($currentTab !== 'about') hidden @endif>
                <span class="section-kicker">Project specifications</span>
                <h2>About SoilSense IoT Platform</h2>
                <p>An academic smart agriculture research system for automated soil testing, sensor calibration verification, and transparent crop &amp; fertilizer decision support.</p>

                <div class="spec-table-wrap">
                    <table class="spec-table">
                        <tbody>
                            <tr>
                                <th scope="row">Platform Version</th>
                                <td><b>SoilSense v1.2.0</b> (Academic Build 2026.11)</td>
                            </tr>
                            <tr>
                                <th scope="row">Software Stack</th>
                                <td>Laravel 12 / PHP 8.2+ / FastAPI ML Service / Vite / Chart.js 4</td>
                            </tr>
                            <tr>
                                <th scope="row">Hardware Architecture</th>
                                <td>ESP32 30-Pin NodeMCU (Dual-core Xtensa LX6 @ 240MHz)</td>
                            </tr>
                            <tr>
                                <th scope="row">Supported Sensors</th>
                                <td>DFRobot Analog pH Sensor, Capacitive Soil Moisture v2.0, DS18B20 Temp Probe, DFRobot EC Meter</td>
                            </tr>
                            <tr>
                                <th scope="row">Agronomic References</th>
                                <td>FAO Irrigation &amp; Drainage Paper 29, Philippine DA-BAR Horticulture Compendium, PCARRD Farm Bulletins, East-West Seed Agronomy Guides</td>
                            </tr>
                            <tr>
                                <th scope="row">Security &amp; Integrity Rules</th>
                                <td>Uncalibrated readings flagged transparently; Missing EC flags salinity risk without inventing scores; Device tokens hashed via SHA-256.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="settings-actions">
                    <a href="{{ route('landing') }}" class="btn btn-ghost">
                        <span>Visit Public Research Overview</span>
                        <x-icon name="chevron" :size="15" />
                    </a>
                </div>
            </section>
        </div>
    </div>

    <!-- Security Disclosure Notice -->
    <div class="notice evidence" style="margin-top: 18px;">
        <x-icon name="settings" />
        <div>
            <b>Academic prototype security note</b>
            <span>Device credential tokens are stored as SHA-256 hashes in <code>iot_devices</code>. Private API keys for backend ML inference are never exposed in client markup.</span>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const navButtons = Array.from(document.querySelectorAll('#settingsNav button[data-tab]'));
            const tabPanels = document.querySelectorAll('.tab-panel');

            function switchTab(tabName) {
                navButtons.forEach(btn => {
                    const isActive = btn.getAttribute('data-tab') === tabName;
                    btn.classList.toggle('active', isActive);
                    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                tabPanels.forEach(panel => {
                    panel.hidden = panel.id !== ('panel-' + tabName);
                });

                const url = new URL(window.location);
                url.searchParams.set('tab', tabName);
                window.history.replaceState({}, '', url);
            }

            navButtons.forEach((btn, idx) => {
                btn.addEventListener('click', function () {
                    switchTab(this.getAttribute('data-tab'));
                });
                btn.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                        e.preventDefault();
                        const next = navButtons[(idx + 1) % navButtons.length];
                        next.click();
                        next.focus();
                    } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                        e.preventDefault();
                        const prev = navButtons[(idx - 1 + navButtons.length) % navButtons.length];
                        prev.click();
                        prev.focus();
                    }
                });
            });

            const avatarFileInput = document.getElementById('avatarFileInput');
            const avatarPreviewContainer = document.getElementById('avatarPreviewContainer');

            if (avatarFileInput && avatarPreviewContainer) {
                avatarFileInput.addEventListener('change', function (e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function (event) {
                            avatarPreviewContainer.innerHTML = `<img src="${event.target.result}" alt="Preview" class="avatar large avatar-img">`;
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }

            const inputName = document.getElementById('inputName');
            const inputRole = document.getElementById('inputRole');
            const displayUserName = document.getElementById('displayUserName');
            const displayUserRole = document.getElementById('displayUserRole');

            if (inputName && displayUserName) {
                inputName.addEventListener('input', function () {
                    displayUserName.textContent = this.value || 'User Name';
                });
            }
            if (inputRole && displayUserRole) {
                inputRole.addEventListener('change', function () {
                    displayUserRole.textContent = this.value;
                });
            }
        });

        function copyEndpointUrl() {
            const endpointInput = document.getElementById('apiEndpointUrl');
            const copyBtn = document.getElementById('copyUrlBtn');
            if (!endpointInput) return;

            endpointInput.select();
            endpointInput.setSelectionRange(0, 99999);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(endpointInput.value);
            } else {
                document.execCommand('copy');
            }
            if (copyBtn) {
                const label = copyBtn.querySelector('span');
                if (label) {
                    label.textContent = 'Copied!';
                    window.setTimeout(() => { label.textContent = 'Copy URL'; }, 1800);
                }
            }
            if (window.SoilSenseUI) {
                window.SoilSenseUI.toast('Ingestion endpoint URL copied to clipboard.', 'success');
            }
        }
    </script>
    @endpush
@endsection
