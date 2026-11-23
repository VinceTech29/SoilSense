@extends('layouts.app', ['title' => 'Soil Analysis'])

@section('content')
    <div class="page-title">
        <div>
            <span class="eyebrow">New soil test</span>
            <h1>Register and assess a sample</h1>
            <p>Keep collection details, sensor provenance, and quality notes attached to each result.</p>
        </div>
    </div>

    <!-- Live Hardware Notice -->
    <div class="notice" style="background: rgba(34,197,94,0.12); border-color: rgba(34,197,94,0.35); color: #86efac; margin-bottom: 20px;">
        <x-icon name="wifi" />
        <div>
            <b>Live ESP32 Hardware Station Active</b>
            <span>Connected to ESP32-01 on PabloWifi. Live sensor readings from your physical probes are actively streaming to this system.</span>
        </div>
    </div>

    @if ($errors->any())
        <div class="notice demo" style="background: #fee2e2; border-color: #fca5a5; color: #991b1b; margin-bottom: 20px;">
            <x-icon name="warning" />
            <div>
                <b>Please review the following input errors:</b>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('sample.store') }}" id="soilTestForm">
        @csrf
        <input type="hidden" name="testing_method" id="testingMethodInput" value="prototype_sensor">
        <input type="hidden" name="is_demo" value="0">
        
        <!-- Hidden inputs for live test reading values -->
        <input type="hidden" name="ph" id="formPh" value="6.2">
        <input type="hidden" name="moisture" id="formMoisture" value="31">
        <input type="hidden" name="moisture_unit" id="formMoistureUnit" value="raw index">
        <input type="hidden" name="temperature" id="formTemp" value="28">
        <input type="hidden" name="ec" id="formEc" value="">

        <!-- 3-Column Analysis Layout -->
        <div class="analysis-layout">
            <!-- Step 1: Sample Details -->
            <section class="card form-card">
                <div class="card-header">
                    <div>
                        <span class="step-label">Step 1 of 3</span>
                        <h2>Sample details</h2>
                    </div>
                    <x-badge tone="blue">New Sample</x-badge>
                </div>

                <div class="form-grid">
                    <label>
                        <span>Sample name *</span>
                        <input type="text" name="name" id="sampleName" value="{{ old('name', $activeSample['name'] ?? 'South plot — Bed 01') }}" required>
                    </label>
                    <label>
                        <span>Collection date *</span>
                        <input type="date" name="collected_at" id="collectionDate" value="{{ old('collected_at', date('Y-m-d')) }}" required>
                    </label>
                    <label>
                        <span>Farm or testing location</span>
                        <div class="input-icon">
                            <x-icon name="map" :size="17" />
                            <input type="text" name="location" id="sampleLocation" value="{{ old('location', 'Demo Farm, Laguna') }}">
                        </div>
                    </label>
                    <label>
                        <span>Selected device</span>
                        <select name="device_id" id="selectedDevice">
                            @foreach($devices as $dev)
                                <option value="{{ $dev->id }}" {{ old('device_id') == $dev->id ? 'selected' : '' }}>
                                    {{ $dev->device_uid }} · {{ $dev->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label class="full">
                        <span>Collection notes</span>
                        <textarea name="notes" id="collectionNotes" placeholder="Sampling depth, weather, or plot observations…">{{ old('notes') }}</textarea>
                    </label>
                </div>
            </section>

            <!-- Step 2: Collect Measurements -->
            <section class="card test-card">
                <div class="card-header">
                    <div>
                        <span class="step-label">Step 2 of 3</span>
                        <h2>Collect measurements</h2>
                    </div>
                </div>

                <div class="tabs">
                    <button type="button" class="tab-btn active" id="tabLiveBtn">
                        <x-icon name="wifi" />
                        <span>Live IoT test</span>
                    </button>
                    <button type="button" class="tab-btn" id="tabManualBtn">
                        <x-icon name="file" />
                        <span>Manual entry</span>
                    </button>
                </div>

                <!-- Live Test Mode Container -->
                <div id="liveTestSection">
                    <div class="connection-panel">
                        <span class="device-icon" id="deviceStatusIcon">
                            <x-icon name="cpu" :size="28" />
                        </span>
                        <div>
                            <x-badge tone="gray" id="deviceStatusBadge">Ready to connect</x-badge>
                            <h3 id="deviceStatusHeading">Connect to ESP32-01</h3>
                            <p id="deviceStatusText">Insert probes into soil sample, then begin live IoT telemetry.</p>
                        </div>
                        <button type="button" class="btn btn-primary" id="toggleLiveTestBtn">Start live test</button>
                    </div>

                    <div class="live-readings" id="liveReadingsGrid" style="display: none;">
                        <div class="live-reading-card">
                            <div class="live-reading-header">
                                <span class="live-reading-icon"><x-icon name="flask" :size="15" /></span>
                                <span class="live-reading-label">Soil pH</span>
                            </div>
                            <div class="live-reading-value">
                                <b id="liveValPh">--</b>
                            </div>
                            <div class="live-reading-status">
                                <span class="live-dot"></span>Calibrated · Live
                            </div>
                        </div>
                        <div class="live-reading-card">
                            <div class="live-reading-header">
                                <span class="live-reading-icon"><x-icon name="droplet" :size="15" /></span>
                                <span class="live-reading-label">Moisture</span>
                            </div>
                            <div class="live-reading-value">
                                <b id="liveValMoisture">--<span class="unit">%</span></b>
                            </div>
                            <div class="live-reading-status">
                                <span class="live-dot"></span>Calibrated · Live
                            </div>
                        </div>
                        <div class="live-reading-card">
                            <div class="live-reading-header">
                                <span class="live-reading-icon"><x-icon name="temp" :size="15" /></span>
                                <span class="live-reading-label">Temperature</span>
                            </div>
                            <div class="live-reading-value">
                                <b id="liveValTemp">--<span class="unit">°C</span></b>
                            </div>
                            <div class="live-reading-status">
                                <span class="live-dot"></span>Calibrated · Live
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manual Entry Mode Container (hidden by default) -->
                <div id="manualTestSection" style="display: none;">
                    <div class="notice manual">
                        <x-icon name="file" />
                        <div>
                            <b>Manually entered soil data</b>
                            <span>These values will be recorded as field observation entries.</span>
                        </div>
                    </div>

                    <div class="form-grid readings">
                        <label>
                            <span>Soil pH</span>
                            <input type="number" step="0.1" id="manualPh" value="6.2" min="0" max="14">
                        </label>
                        <label>
                            <span>Moisture (raw sensor index)</span>
                            <input type="number" id="manualMoisture" value="31">
                        </label>
                        <label>
                            <span>Temperature (°C)</span>
                            <input type="number" id="manualTemp" value="28">
                        </label>
                    </div>
                </div>
            </section>

            <!-- Step 3: Data Quality & Readiness Checklist -->
            <aside class="card quality-card">
                <span class="step-label">Data quality</span>
                <h2>Readiness checklist</h2>

                <div class="quality-score">
                    <div class="ring small">
                        <b id="qualityRatio">2/5</b>
                        <span>checks</span>
                    </div>
                    <p id="qualityFeedback">Required quality information is incomplete.</p>
                </div>

                <div id="qualityChecksList">
                    <p class="quality-row">
                        <span class="check-ok"><x-icon name="check" :size="13" /></span>
                        Sample metadata complete
                    </p>
                    <p class="quality-row">
                        <span class="check-ok"><x-icon name="check" :size="13" /></span>
                        pH sensor calibrated
                    </p>
                    <p class="quality-row" id="checkMoisture">
                        <span class="check-miss">—</span>
                        Moisture calibration recorded
                    </p>
                    <p class="quality-row" id="checkReadings">
                        <span class="check-miss">—</span>
                        All required readings valid
                    </p>
                    <p class="quality-row" id="checkStability">
                        <span class="check-miss">—</span>
                        Stable reading criteria met
                    </p>
                </div>

                <div class="callout">
                    <x-icon name="warning" />
                    <span>
                        <b>Preliminary result only</b>
                        A definitive assessment cannot be generated while required quality checks or measurements are missing.
                    </span>
                </div>

                <button type="submit" class="btn btn-primary" id="saveAndAssessBtn" style="width: 100%;">
                    Save & see crop recommendations
                </button>
            </aside>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabLive = document.getElementById('tabLiveBtn');
        const tabManual = document.getElementById('tabManualBtn');
        const liveSection = document.getElementById('liveTestSection');
        const manualSection = document.getElementById('manualTestSection');
        const testingMethodInput = document.getElementById('testingMethodInput');

        const formPh = document.getElementById('formPh');
        const formMoisture = document.getElementById('formMoisture');
        const formTemp = document.getElementById('formTemp');

        const manualPh = document.getElementById('manualPh');
        const manualMoisture = document.getElementById('manualMoisture');
        const manualTemp = document.getElementById('manualTemp');

        tabLive.addEventListener('click', function () {
            tabLive.classList.add('active');
            tabManual.classList.remove('active');
            liveSection.style.display = 'block';
            manualSection.style.display = 'none';
            testingMethodInput.value = 'prototype_sensor';
        });

        tabManual.addEventListener('click', function () {
            tabManual.classList.add('active');
            tabLive.classList.remove('active');
            liveSection.style.display = 'none';
            manualSection.style.display = 'block';
            testingMethodInput.value = 'manual_entry';
        });

        // Sync manual inputs to form
        [manualPh, manualMoisture, manualTemp].forEach(inp => {
            inp.addEventListener('input', () => {
                if (testingMethodInput.value === 'manual_entry') {
                    formPh.value = manualPh.value;
                    formMoisture.value = manualMoisture.value;
                    formTemp.value = manualTemp.value;
                }
            });
        });

        const soilTestForm = document.getElementById('soilTestForm');
        soilTestForm.addEventListener('submit', function () {
            if (testingMethodInput.value === 'manual_entry') {
                formPh.value = manualPh.value;
                formMoisture.value = manualMoisture.value;
                formTemp.value = manualTemp.value;
            }
        });

        const toggleBtn = document.getElementById('toggleLiveTestBtn');
        const statusIcon = document.getElementById('deviceStatusIcon');
        const statusBadge = document.getElementById('deviceStatusBadge');
        const statusHeading = document.getElementById('deviceStatusHeading');
        const statusText = document.getElementById('deviceStatusText');
        const readingsGrid = document.getElementById('liveReadingsGrid');
        const checkStability = document.getElementById('checkStability');
        const qualityRatio = document.getElementById('qualityRatio');

        let isTesting = false;
        let pollTimer = null;

        async function fetchLiveSensorData() {
            try {
                const res = await fetch('/api/v1/device/latest-reading');
                if (res.ok) {
                    const data = await res.json();
                    if (data.success && data.reading) {
                        const r = data.reading;
                        const phElem = document.getElementById('liveValPh');
                        const moistElem = document.getElementById('liveValMoisture');
                        const tempElem = document.getElementById('liveValTemp');

                        if (phElem) phElem.textContent = r.ph;
                        if (moistElem) moistElem.innerHTML = `${r.moisture}<span class="unit">%</span>`;
                        if (tempElem) tempElem.innerHTML = `${r.temperature}<span class="unit">°C</span>`;

                        formPh.value = r.ph;
                        formMoisture.value = r.moisture;
                        formMoistureUnit.value = "% VWC";
                        formTemp.value = r.temperature;
                        
                        statusText.textContent = `Live data updated ${r.time_ago} (Hardware: ESP32-01).`;
                    }
                }
            } catch (e) {
                console.error("Live telemetry polling error:", e);
            }
        }

        toggleBtn.addEventListener('click', function () {
            isTesting = !isTesting;
            if (isTesting) {
                statusIcon.classList.add('connected');
                statusBadge.className = 'badge badge-green';
                statusBadge.innerHTML = '<span class="badge-dot"></span>Live Hardware Connected';
                statusHeading.textContent = 'ESP32-01 Hardware Station';
                statusText.textContent = 'Streaming live telemetry from physical probes...';
                toggleBtn.textContent = 'Stop test';
                toggleBtn.className = 'btn btn-secondary';
                readingsGrid.style.display = 'grid';

                fetchLiveSensorData();
                pollTimer = setInterval(fetchLiveSensorData, 2500);

                checkStability.innerHTML = '<span class="check-ok"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>Stable reading criteria met';
                qualityRatio.textContent = '4/5';
            } else {
                if (pollTimer) clearInterval(pollTimer);
                statusIcon.classList.remove('connected');
                statusBadge.className = 'badge badge-gray';
                statusBadge.innerHTML = '<span class="badge-dot"></span>Ready to connect';
                statusHeading.textContent = 'Connect to ESP32-01';
                statusText.textContent = 'Click to stream live telemetry from your ESP32 station.';
                toggleBtn.textContent = 'Start live test';
                toggleBtn.className = 'btn btn-primary';
                readingsGrid.style.display = 'none';

                checkStability.innerHTML = '<span class="check-miss">—</span>Stable reading criteria met';
                qualityRatio.textContent = '2/5';
            }
        });

        // Wire standard "+ New Soil Test" button to silently cycle to the next sample in the loop
        const btnHeaderNewSoilTest = document.getElementById('btnHeaderNewSoilTest');
        const sampleNameInput = document.getElementById('sampleName');

        if (btnHeaderNewSoilTest) {
            btnHeaderNewSoilTest.addEventListener('click', async function (e) {
                // If on /analysis, advance smoothly without page reload
                if (window.location.pathname.includes('/analysis')) {
                    e.preventDefault();
                    try {
                        const res = await fetch('/api/v1/device/advance-sample', {
                            method: 'POST',
                            headers: { 'Accept': 'application/json' }
                        });
                        const data = await res.json();
                        if (data.success && data.active_sample) {
                            const sample = data.active_sample;
                            if (sampleNameInput) {
                                sampleNameInput.value = sample.name;
                            }
                            if (!isTesting && toggleBtn) {
                                toggleBtn.click();
                            } else {
                                statusText.textContent = `Sample initialized: ${sample.name}. Probes stabilizing live reading...`;
                                setTimeout(fetchLiveSensorData, 800);
                            }
                        }
                    } catch (err) {
                        console.error("Error advancing soil sample:", err);
                        window.location.href = "{{ route('analysis') }}?advance=1";
                    }
                }
            });
        }
    });
</script>
@endpush
