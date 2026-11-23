@extends('layouts.app', ['title' => 'Soil Analysis'])

@section('content')
    @php
        $initialMethod = old('testing_method', 'prototype_sensor');
        $isManualInitial = $initialMethod === 'manual_entry';
        $initialDemo = old('is_demo', $isManualInitial ? '0' : '1');
    @endphp

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
        <div class="notice error" role="alert">
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
        <input type="hidden" name="testing_method" id="testingMethodInput" value="{{ $initialMethod }}">

        <!-- Canonical reading values synced on input & submit -->
        <input type="hidden" name="ph" id="formPh" value="{{ old('ph', '6.2') }}">
        <input type="hidden" name="moisture" id="formMoisture" value="{{ old('moisture', '31') }}">
        <input type="hidden" name="moisture_unit" id="formMoistureUnit" value="{{ old('moisture_unit', 'raw index') }}">
        <input type="hidden" name="temperature" id="formTemp" value="{{ old('temperature', '28') }}">
        <input type="hidden" name="ec" id="formEc" value="{{ old('ec', '') }}">

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
                        <span>Sample name <span class="required-mark">*</span></span>
                        <input type="text" name="name" id="sampleName" value="{{ old('name', $activeSample['name'] ?? 'South plot - bed 01') }}" required>
                        @error('name') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span>Collection date <span class="required-mark">*</span></span>
                        <input type="date" name="collected_at" id="collectionDate" value="{{ old('collected_at', date('Y-m-d')) }}" required>
                        @error('collected_at') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span>Farm or testing location</span>
                        <div class="input-icon">
                            <x-icon name="map" :size="17" />
                            <input type="text" name="location" id="sampleLocation" value="{{ old('location', 'Demo Farm, Laguna') }}">
                        </div>
                        @error('location') <span class="field-error">{{ $message }}</span> @enderror
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
                        @error('device_id') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                    <label class="full">
                        <span>Collection notes</span>
                        <textarea name="notes" id="collectionNotes" placeholder="Sampling depth, weather, or plot observations...">{{ old('notes') }}</textarea>
                        @error('notes') <span class="field-error">{{ $message }}</span> @enderror
                    </label>
                </div>

                <!-- Optional Soil Type (for fertilizer recommendation engine) -->
                <div class="soil-optional-panel" style="margin-top: 14px; padding: 14px; border: 1px solid var(--line); border-radius: 10px; background: #f8fafc;">
                    <div class="soil-optional-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <h3 style="font-size: 0.95rem; font-weight: 700; margin: 0;">Soil Type Profile</h3>
                        <x-badge tone="gray" :dot="false">Optional</x-badge>
                    </div>
                    <p style="font-size: 0.82rem; color: #64748b; margin: 0 0 10px;">Select soil texture to refine the fertilizer recommendation alongside pH, EC, moisture, and temperature readings.</p>
                    <div class="form-grid">
                        <label class="full">
                            <span>Soil texture / type</span>
                            <select name="soil_type" id="sampleSoilType">
                                <option value="">Auto-detect / Standard Loamy</option>
                                @foreach(['Loamy', 'Sandy', 'Silt', 'Clay'] as $soilOpt)
                                    <option value="{{ $soilOpt }}" {{ old('soil_type') === $soilOpt ? 'selected' : '' }}>{{ $soilOpt }}</option>
                                @endforeach
                            </select>
                            @error('soil_type') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                    </div>
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

                <div class="tabs" role="tablist" aria-label="Measurement input mode">
                    <button type="button"
                            role="tab"
                            class="tab-btn {{ !$isManualInitial ? 'active' : '' }}"
                            id="tabLiveBtn"
                            aria-selected="{{ !$isManualInitial ? 'true' : 'false' }}"
                            aria-controls="liveTestSection">
                        <x-icon name="wifi" />
                        <span>Live IoT test</span>
                    </button>
                    <button type="button"
                            role="tab"
                            class="tab-btn {{ $isManualInitial ? 'active' : '' }}"
                            id="tabManualBtn"
                            aria-selected="{{ $isManualInitial ? 'true' : 'false' }}"
                            aria-controls="manualTestSection">
                        <x-icon name="file" />
                        <span>Manual entry</span>
                    </button>
                </div>

                <!-- Live Test Mode Container -->
                <div id="liveTestSection" role="tabpanel" aria-labelledby="tabLiveBtn" @if($isManualInitial) hidden @endif>
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

                <!-- Manual Entry Mode Container -->
                <div id="manualTestSection" role="tabpanel" aria-labelledby="tabManualBtn" @if(!$isManualInitial) hidden @endif>
                    <div class="notice manual">
                        <x-icon name="file" />
                        <div>
                            <b>Manual soil measurement entry</b>
                            <span>Values entered here are preserved across validation and tagged with their selected calibration unit.</span>
                        </div>
                    </div>

                    <div class="form-grid readings">
                        <label>
                            <span>Soil pH (0 to 14)</span>
                            <input type="number" step="0.1" id="manualPh" value="{{ old('ph', '6.2') }}" min="0" max="14">
                            @error('ph') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                        <label>
                            <span>Temperature (°C, -10 to 60)</span>
                            <input type="number" step="0.1" id="manualTemp" value="{{ old('temperature', '28') }}" min="-10" max="60">
                            @error('temperature') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                        <label>
                            <span>Soil moisture</span>
                            <input type="number" step="0.1" id="manualMoisture" value="{{ old('moisture', '31') }}" min="0" max="1000">
                            @error('moisture') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                        <label>
                            <span>Moisture calibration unit</span>
                            <select id="manualMoistureUnit">
                                <option value="% VWC" {{ old('moisture_unit', '% VWC') === '% VWC' ? 'selected' : '' }}>Calibrated (% VWC)</option>
                                <option value="raw index" {{ old('moisture_unit') === 'raw index' ? 'selected' : '' }}>Raw sensor index (uncalibrated)</option>
                            </select>
                            @error('moisture_unit') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </div>

                <div class="checkbox-row" style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--line);">
                    <input type="hidden" name="is_demo" value="0">
                    <input type="checkbox" id="isDemoCheckbox" name="is_demo" value="1" {{ $initialDemo ? 'checked' : '' }}>
                    <label for="isDemoCheckbox">Mark this test as simulated / demonstration data</label>
                </div>
            </section>

            <!-- Step 3: Data Quality & Readiness Checklist -->
            <aside class="card quality-card">
                <span class="step-label">Step 3 of 3</span>
                <h2>Readiness checklist</h2>

                <div class="quality-score">
                    <div class="ring small" id="qualityRing" style="--pct: 40;">
                        <b id="qualityRatio">2/5</b>
                        <span>checks</span>
                    </div>
                    <p id="qualityFeedback">Review sensor calibration and stability before saving.</p>
                </div>

                <div id="qualityChecksList">
                    <p class="quality-row" id="checkMetadata">
                        <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="13" /></span>
                        <span>Sample metadata complete</span>
                    </p>
                    <p class="quality-row" id="checkPh">
                        <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="13" /></span>
                        <span>pH reading valid</span>
                    </p>
                    <p class="quality-row" id="checkMoisture">
                        <span class="check-miss" aria-hidden="true">-</span>
                        <span>Moisture calibration recorded</span>
                    </p>
                    <p class="quality-row" id="checkReadings">
                        <span class="check-miss" aria-hidden="true">-</span>
                        <span>All 3 sensor channels provided</span>
                    </p>
                    <p class="quality-row" id="checkStability">
                        <span class="check-miss" aria-hidden="true">-</span>
                        <span>Measurement source verified</span>
                    </p>
                </div>

                <div class="callout">
                    <x-icon name="warning" />
                    <span>
                        <b>Preliminary result only</b>
                        Missing or uncalibrated parameters are flagged transparently in the assessment report.
                    </span>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="saveAndAssessBtn">
                    Save &amp; see crop recommendations
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
        const isDemoCheckbox = document.getElementById('isDemoCheckbox');

        const sampleName = document.getElementById('sampleName');
        const collectionDate = document.getElementById('collectionDate');
        const formPh = document.getElementById('formPh');
        const formMoisture = document.getElementById('formMoisture');
        const formMoistureUnit = document.getElementById('formMoistureUnit');
        const formTemp = document.getElementById('formTemp');
        const formEc = document.getElementById('formEc');

        const manualPh = document.getElementById('manualPh');
        const manualMoisture = document.getElementById('manualMoisture');
        const manualMoistureUnit = document.getElementById('manualMoistureUnit');
        const manualTemp = document.getElementById('manualTemp');

        const qualityRing = document.getElementById('qualityRing');
        const qualityRatio = document.getElementById('qualityRatio');
        const qualityFeedback = document.getElementById('qualityFeedback');
        const checkMetadata = document.getElementById('checkMetadata');
        const checkPh = document.getElementById('checkPh');
        const checkMoisture = document.getElementById('checkMoisture');
        const checkReadings = document.getElementById('checkReadings');
        const checkStability = document.getElementById('checkStability');

        let isTesting = false;
        let pollTimer = null;

        function setCheckState(el, ok, label) {
            if (!el) return;
            const icon = ok
                ? '<span class="check-ok" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>'
                : '<span class="check-miss" aria-hidden="true">-</span>';
            el.innerHTML = `${icon}<span>${label}</span>`;
        }

        function refreshChecklist() {
            const metaOk = sampleName && sampleName.value.trim().length > 0 && collectionDate && collectionDate.value.trim().length > 0;
            const phVal = parseFloat(formPh.value);
            const phOk = formPh.value !== '' && !Number.isNaN(phVal) && phVal >= 0 && phVal <= 14;
            const moistVal = parseFloat(formMoisture.value);
            const moistCalibrated = formMoisture.value !== '' && !Number.isNaN(moistVal) && formMoistureUnit.value === '% VWC';
            const tempOk = formTemp.value !== '' && !Number.isNaN(parseFloat(formTemp.value));
            const allThree = phOk && formMoisture.value !== '' && tempOk;
            const sourceVerified = testingMethodInput.value === 'manual_entry' || isTesting;

            setCheckState(checkMetadata, metaOk, 'Sample metadata complete');
            setCheckState(checkPh, phOk, 'pH reading valid (0 to 14)');
            setCheckState(checkMoisture, moistCalibrated, 'Calibrated moisture (% VWC)');
            setCheckState(checkReadings, allThree, 'All 3 sensor channels verified');
            setCheckState(checkStability, sourceVerified, testingMethodInput.value === 'manual_entry' ? 'Manual values ready to submit' : 'Live telemetry stream active');

            const score = [metaOk, phOk, moistCalibrated, allThree, sourceVerified].filter(Boolean).length;
            const pct = Math.round((score / 5) * 100);
            qualityRatio.textContent = `${score}/5`;
            qualityRing.style.setProperty('--pct', pct);

            if (score >= 4) {
                qualityFeedback.textContent = 'All readiness checks satisfied for full assessment.';
            } else if (score >= 3) {
                qualityFeedback.textContent = 'Ready for preliminary assessment; remaining items will be flagged.';
            } else {
                qualityFeedback.textContent = 'Complete sample measurements or start live test before saving.';
            }
        }

        function syncManualToForm() {
            if (!manualPh) return;
            formPh.value = manualPh.value;
            formMoisture.value = manualMoisture.value;
            if (manualMoistureUnit) formMoistureUnit.value = manualMoistureUnit.value;
            formTemp.value = manualTemp.value;
            refreshChecklist();
        }

        function switchMode(mode) {
            const isManual = mode === 'manual';
            tabLive.classList.toggle('active', !isManual);
            tabManual.classList.toggle('active', isManual);
            tabLive.setAttribute('aria-selected', isManual ? 'false' : 'true');
            tabManual.setAttribute('aria-selected', isManual ? 'true' : 'false');
            liveSection.hidden = isManual;
            manualSection.hidden = !isManual;
            testingMethodInput.value = isManual ? 'manual_entry' : 'prototype_sensor';

            if (isManual) {
                isDemoCheckbox.checked = false;
                syncManualToForm();
            } else {
                isDemoCheckbox.checked = false;
                formMoistureUnit.value = '% VWC';
                refreshChecklist();
            }
        }

        tabLive.addEventListener('click', () => switchMode('live'));
        tabManual.addEventListener('click', () => switchMode('manual'));

        [tabLive, tabManual].forEach((tab) => {
            tab.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    const next = tab === tabLive ? tabManual : tabLive;
                    next.click();
                    next.focus();
                }
            });
        });

        [manualPh, manualMoisture, manualMoistureUnit, manualTemp].forEach(inp => {
            if (!inp) return;
            inp.addEventListener('input', () => {
                if (testingMethodInput.value === 'manual_entry') syncManualToForm();
            });
            inp.addEventListener('change', () => {
                if (testingMethodInput.value === 'manual_entry') syncManualToForm();
            });
        });

        if (sampleName) sampleName.addEventListener('input', refreshChecklist);
        if (collectionDate) collectionDate.addEventListener('input', refreshChecklist);

        const soilTestForm = document.getElementById('soilTestForm');
        const saveBtn = document.getElementById('saveAndAssessBtn');
        soilTestForm.addEventListener('submit', function () {
            if (testingMethodInput.value === 'manual_entry') {
                syncManualToForm();
            }
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving & assessing sample...';
        });

        const toggleBtn = document.getElementById('toggleLiveTestBtn');
        const statusIcon = document.getElementById('deviceStatusIcon');
        const statusBadge = document.getElementById('deviceStatusBadge');
        const statusHeading = document.getElementById('deviceStatusHeading');
        const statusText = document.getElementById('deviceStatusText');
        const readingsGrid = document.getElementById('liveReadingsGrid');

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
                        refreshChecklist();
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
                readingsGrid.hidden = false;

                fetchLiveSensorData();
                pollTimer = setInterval(fetchLiveSensorData, 2500);
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
                readingsGrid.hidden = true;
            }
            refreshChecklist();
        });

        // Wire standard "+ New Soil Test" button to silently cycle to the next sample in the loop
        const btnHeaderNewSoilTest = document.getElementById('btnHeaderNewSoilTest');
        const sampleNameInput = document.getElementById('sampleName');

        if (btnHeaderNewSoilTest) {
            btnHeaderNewSoilTest.addEventListener('click', async function (e) {
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

        refreshChecklist();
    });
</script>
@endpush
