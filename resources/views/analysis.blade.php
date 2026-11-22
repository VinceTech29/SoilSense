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

    <!-- Demonstration Notice -->
    <div class="notice demo">
        <x-icon name="warning" />
        <div>
            <b>Demonstration environment</b>
            <span>When running the simulated ESP32 test without physical hardware, readings are tagged as demo data.</span>
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
                        <input type="text" name="name" id="sampleName" value="{{ old('name', 'South plot - bed 04') }}" required>
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

                <!-- Optional Soil Type (co-located with Sample Details) -->
                <div class="soil-optional-panel">
                    <div class="soil-optional-head">
                        <h3>Optional Soil Type</h3>
                        <x-badge tone="gray" :dot="false">Optional</x-badge>
                    </div>
                    <p>Specify soil texture to refine the 4-sensor fertilizer recommendation alongside pH, EC, moisture, and temperature readings.</p>
                    <div class="form-grid" style="margin-top: 8px;">
                        <label class="full">
                            <span>Soil type</span>
                            <select name="soil_type" id="sampleSoilType">
                                <option value="">Select soil type (optional)</option>
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
                            <p id="deviceStatusText">Place probes correctly, then begin a simulated connection.</p>
                        </div>
                        <button type="button" class="btn btn-primary" id="toggleLiveTestBtn">Start live test</button>
                    </div>

                    <div class="live-readings" id="liveReadingsGrid" hidden>
                        <div>
                            <span><x-icon name="flask" :size="17" /> Soil pH</span>
                            <b id="liveValPh">6.2</b>
                            <small>Stable · demo</small>
                        </div>
                        <div>
                            <span><x-icon name="droplet" :size="17" /> Moisture</span>
                            <b id="liveValMoisture">31 raw</b>
                            <small>Uncalibrated</small>
                        </div>
                        <div>
                            <span><x-icon name="temp" :size="17" /> Temperature</span>
                            <b id="liveValTemp">28 °C</b>
                            <small>Stable · demo</small>
                        </div>
                        <div>
                            <span><x-icon name="bolt" :size="17" /> EC</span>
                            <b id="liveValEc">1.15 mS/cm</b>
                            <small>Stable · demo</small>
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
                                <option value="% VWC" {{ old('moisture_unit', 'raw index') === '% VWC' ? 'selected' : '' }}>Calibrated (% VWC)</option>
                                <option value="raw index" {{ old('moisture_unit', 'raw index') === 'raw index' ? 'selected' : '' }}>Raw sensor index (uncalibrated)</option>
                            </select>
                            @error('moisture_unit') <span class="field-error">{{ $message }}</span> @enderror
                        </label>
                        <label class="full">
                            <span>Electrical conductivity (mS/cm, optional)</span>
                            <input type="number" step="0.01" id="manualEc" value="{{ old('ec', '') }}" min="0" max="30" placeholder="e.g. 1.25 (leave blank if not measured)">
                            @error('ec') <span class="field-error">{{ $message }}</span> @enderror
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
                        <span>All 4 sensor channels provided</span>
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
        const manualEc = document.getElementById('manualEc');

        const qualityRing = document.getElementById('qualityRing');
        const qualityRatio = document.getElementById('qualityRatio');
        const qualityFeedback = document.getElementById('qualityFeedback');
        const checkMetadata = document.getElementById('checkMetadata');
        const checkPh = document.getElementById('checkPh');
        const checkMoisture = document.getElementById('checkMoisture');
        const checkReadings = document.getElementById('checkReadings');
        const checkStability = document.getElementById('checkStability');

        let isTesting = false;

        function setCheckState(el, ok, label) {
            if (!el) return;
            const icon = ok
                ? '<span class="check-ok" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>'
                : '<span class="check-miss" aria-hidden="true">-</span>';
            el.innerHTML = `${icon}<span>${label}</span>`;
        }

        function refreshChecklist() {
            const metaOk = sampleName.value.trim().length > 0 && collectionDate.value.trim().length > 0;
            const phVal = parseFloat(formPh.value);
            const phOk = formPh.value !== '' && !Number.isNaN(phVal) && phVal >= 0 && phVal <= 14;
            const moistVal = parseFloat(formMoisture.value);
            const moistCalibrated = formMoisture.value !== '' && !Number.isNaN(moistVal) && formMoistureUnit.value === '% VWC';
            const tempOk = formTemp.value !== '' && !Number.isNaN(parseFloat(formTemp.value));
            const ecOk = formEc.value !== '' && !Number.isNaN(parseFloat(formEc.value));
            const allFour = phOk && formMoisture.value !== '' && tempOk && ecOk;
            const sourceVerified = testingMethodInput.value === 'manual_entry' || isTesting;

            setCheckState(checkMetadata, metaOk, 'Sample metadata complete');
            setCheckState(checkPh, phOk, 'pH reading valid (0 to 14)');
            setCheckState(checkMoisture, moistCalibrated, 'Calibrated moisture (% VWC)');
            setCheckState(checkReadings, allFour, 'All 4 sensor channels provided');
            setCheckState(checkStability, sourceVerified, testingMethodInput.value === 'manual_entry' ? 'Manual values ready to submit' : 'Live telemetry stream active');

            const score = [metaOk, phOk, moistCalibrated, allFour, sourceVerified].filter(Boolean).length;
            const pct = Math.round((score / 5) * 100);
            qualityRatio.textContent = `${score}/5`;
            qualityRing.style.setProperty('--pct', pct);

            if (score >= 5) {
                qualityFeedback.textContent = 'All readiness checks satisfied for full assessment.';
            } else if (score >= 3) {
                qualityFeedback.textContent = 'Ready for preliminary assessment; remaining items will be flagged.';
            } else {
                qualityFeedback.textContent = 'Complete sample measurements or start live test before saving.';
            }
        }

        function syncManualToForm() {
            formPh.value = manualPh.value;
            formMoisture.value = manualMoisture.value;
            formMoistureUnit.value = manualMoistureUnit.value;
            formTemp.value = manualTemp.value;
            formEc.value = manualEc.value;
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
                isDemoCheckbox.checked = true;
                formMoistureUnit.value = 'raw index';
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

        [manualPh, manualMoisture, manualMoistureUnit, manualTemp, manualEc].forEach(inp => {
            inp.addEventListener('input', () => {
                if (testingMethodInput.value === 'manual_entry') syncManualToForm();
            });
            inp.addEventListener('change', () => {
                if (testingMethodInput.value === 'manual_entry') syncManualToForm();
            });
        });

        [sampleName, collectionDate].forEach(inp => inp.addEventListener('input', refreshChecklist));

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

        toggleBtn.addEventListener('click', function () {
            isTesting = !isTesting;
            if (isTesting) {
                statusIcon.classList.add('connected');
                statusBadge.className = 'badge badge-green';
                statusBadge.innerHTML = '<span class="badge-dot"></span>Receiving demo data';
                statusHeading.textContent = 'ESP32-01 connected';
                statusText.textContent = 'Simulated 4-channel readings captured. Ready to save sample.';
                toggleBtn.textContent = 'Stop test';
                toggleBtn.className = 'btn btn-secondary';
                readingsGrid.hidden = false;

                formPh.value = '6.2';
                formMoisture.value = '31';
                formMoistureUnit.value = 'raw index';
                formTemp.value = '28';
                formEc.value = '1.15';
            } else {
                statusIcon.classList.remove('connected');
                statusBadge.className = 'badge badge-gray';
                statusBadge.innerHTML = '<span class="badge-dot"></span>Ready to connect';
                statusHeading.textContent = 'Connect to ESP32-01';
                statusText.textContent = 'Place probes correctly, then begin a simulated connection.';
                toggleBtn.textContent = 'Start live test';
                toggleBtn.className = 'btn btn-primary';
                readingsGrid.hidden = true;
            }
            refreshChecklist();
        });

        refreshChecklist();
    });
</script>
@endpush
