@extends('layouts.app', ['title' => 'Device Monitoring'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Device Monitoring</h1>
            <p>Review connection state, sensor availability, and the latest API communication.</p>
        </div>
        <div class="segmented" id="stateSwitcher" role="group" aria-label="Preview device status state">
            <button type="button" class="active" data-state="online" aria-pressed="true">Online</button>
            <button type="button" data-state="offline" aria-pressed="false">Offline</button>
            <button type="button" data-state="error" aria-pressed="false">Error</button>
        </div>
    </div>

    <!-- Device Hero Banner -->
    <div class="device-hero card state-{{ $device?->status ?? 'online' }}" id="deviceHeroCard">
        <div class="device-identity">
            <span class="device-icon"><x-icon name="cpu" :size="30" /></span>
            <div>
                <span>{{ $device?->name ?? 'Primary soil station' }}</span>
                <h2>{{ $device?->device_uid ?? 'ESP32-01' }}</h2>
                <small>{{ $device?->is_demo ? 'Prototype device · Demo data' : 'Live field hardware' }} · {{ $device?->location ?? 'Laguna Station' }}</small>
            </div>
        </div>
        <div class="connection-state">
            <x-badge :tone="$device && $device->status === 'online' ? 'green' : ($device && $device->status === 'offline' ? 'gray' : 'amber')" id="deviceHeroBadge">
                {{ ucfirst($device?->status ?? 'online') }} · {{ $device?->is_demo ? 'demo' : 'live' }}
            </x-badge>
            <p id="deviceHeroText">
                @if($device && $device->last_seen_at)
                    Last packet received {{ $device->last_seen_at->diffForHumans() }} ({{ $device->last_seen_at->format('M d, g:i A') }}).
                @else
                    No telemetry communication recorded.
                @endif
            </p>
        </div>
        <button type="button" class="btn btn-secondary" id="deviceHeroActionBtn">View API details</button>
    </div>

    <!-- Device Telemetry & API Status Grid -->
    <div class="device-grid">
        <!-- Sensor Inventory -->
        <section class="card sensor-list">
            <div class="card-header">
                <div>
                    <span class="section-kicker">Connected hardware</span>
                    <h2>Sensor inventory</h2>
                </div>
                <x-badge tone="amber">1 needs attention</x-badge>
            </div>

            <div class="sensor-row">
                <span class="sensor-status" aria-hidden="true"></span>
                <div>
                    <b>pH probe</b>
                    <small>Output unit: pH</small>
                </div>
                <x-badge tone="green">Reporting</x-badge>
                <span><small>Calibration</small><b>Calibration recorded</b></span>
            </div>

            <div class="sensor-row">
                <span class="sensor-status amber" aria-hidden="true"></span>
                <div>
                    <b>Capacitive moisture</b>
                    <small>Output unit: % VWC / Raw index</small>
                </div>
                <x-badge tone="amber">Check mode</x-badge>
                <span><small>Calibration</small><b>Verify % VWC curve</b></span>
            </div>

            <div class="sensor-row">
                <span class="sensor-status" aria-hidden="true"></span>
                <div>
                    <b>DS18B20 temperature</b>
                    <small>Output unit: °C</small>
                </div>
                <x-badge tone="green">Reporting</x-badge>
                <span><small>Calibration</small><b>Factory digital probe</b></span>
            </div>
        </section>

        <!-- API Endpoint Communication Panel -->
        <aside class="card api-panel" id="apiStatusPanel">
            <span class="section-kicker">Latest communication</span>
            <h2>API status</h2>

            <div class="api-status">
                <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="15" /></span>
                <div>
                    <b>REST API active</b>
                    <small>POST /api/v1/readings · HTTP 201</small>
                </div>
            </div>

            <p><span>Auth method</span><b>Bearer / X-Device-Token</b></p>
            <p><span>Token hash</span><b>SHA-256 verified</b></p>
            <p><span>Rate limit</span><b>60 req/min</b></p>
            <p><span>Last sync</span><b id="apiPanelLastSync">{{ $device && $device->last_seen_at ? $device->last_seen_at->format('M d · g:i:s A') : 'Awaiting sensor' }}</b></p>
            <p><span>Recent records</span><b>{{ $recentReadings->count() }} telemetry packets</b></p>
            <p><span>Hardware link</span><b>ESP32 HTTP Client</b></p>
        </aside>
    </div>

    <!-- Live ESP32 Hardware Remote Control Panel -->
    <section class="card hardware-control-card" style="margin-bottom: 24px; border: 1.5px solid #22c55e; background: linear-gradient(135deg, rgba(34,197,94,0.06) 0%, rgba(15,23,42,0.85) 100%);">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 12px; border-bottom: 1px solid rgba(255,255,255,0.08);">
            <div>
                <span class="section-kicker" style="color: #22c55e; font-weight: 700;">Two-Way IoT Hardware Control</span>
                <h2 style="font-size: 1.25rem; font-weight: 700; margin: 4px 0;">ESP32 Soil Sample Remote Switcher</h2>
                <p style="font-size: 0.85rem; color: #94a3b8; margin: 0;">Click a soil sample below to remotely switch the active testing profile on your physical ESP32 and 16x2 LCD screen over WiFi.</p>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span id="hwSyncStatusBadge" style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; background: rgba(34,197,94,0.18); color: #4ade80; border: 1px solid rgba(34,197,94,0.3);">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 8px #22c55e;"></span>
                    Active on ESP32: <b id="currentActiveSampleText" style="margin-left: 4px;">{{ $activeSample['sample_code'] ?? 'S1' }} ({{ $activeSample['name'] ?? 'Sample 1: Acidic Peat' }})</b>
                </span>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 16px;">
            <!-- Sample 1 Button -->
            <button type="button" class="hw-sample-btn" data-code="S1" data-ph="4.01" data-quality="ACIDIC"
                style="display: flex; flex-direction: column; align-items: flex-start; padding: 16px; border-radius: 12px; border: 1.5px solid rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.08); text-align: left; cursor: pointer; transition: all 0.2s ease; position: relative;">
                <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: #f87171; text-transform: uppercase;">Sample 1 (Acidic)</span>
                    <span style="font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: rgba(239,68,68,0.25); color: #fca5a5;">Target: 4.01 pH</span>
                </div>
                <b style="font-size: 1.05rem; color: #fff; margin-bottom: 2px;">Acidic Peat Soil</b>
                <small style="color: #94a3b8; font-size: 0.8rem;">Reflects: <span style="color: #f87171; font-weight: 600;">ACIDIC (4.01 ± 0.02)</span></small>
            </button>

            <!-- Sample 2 Button -->
            <button type="button" class="hw-sample-btn" data-code="S2" data-ph="6.86" data-quality="OPTIMAL"
                style="display: flex; flex-direction: column; align-items: flex-start; padding: 16px; border-radius: 12px; border: 1.5px solid rgba(34, 197, 94, 0.4); background: rgba(34, 197, 94, 0.08); text-align: left; cursor: pointer; transition: all 0.2s ease; position: relative;">
                <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: #4ade80; text-transform: uppercase;">Sample 2 (Neutral)</span>
                    <span style="font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: rgba(34,197,94,0.25); color: #86efac;">Target: 6.86 pH</span>
                </div>
                <b style="font-size: 1.05rem; color: #fff; margin-bottom: 2px;">Garden Loam Soil</b>
                <small style="color: #94a3b8; font-size: 0.8rem;">Reflects: <span style="color: #4ade80; font-weight: 600;">OPTIMAL (6.86 ± 0.02)</span></small>
            </button>

            <!-- Sample 3 Button -->
            <button type="button" class="hw-sample-btn" data-code="S3" data-ph="9.18" data-quality="ALKALINE"
                style="display: flex; flex-direction: column; align-items: flex-start; padding: 16px; border-radius: 12px; border: 1.5px solid rgba(59, 130, 246, 0.4); background: rgba(59, 130, 246, 0.08); text-align: left; cursor: pointer; transition: all 0.2s ease; position: relative;">
                <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 6px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: #60a5fa; text-transform: uppercase;">Sample 3 (Alkaline)</span>
                    <span style="font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: rgba(59,130,246,0.25); color: #93c5fd;">Target: 9.18 pH</span>
                </div>
                <b style="font-size: 1.05rem; color: #fff; margin-bottom: 2px;">Chalky Clay Soil</b>
                <small style="color: #94a3b8; font-size: 0.8rem;">Reflects: <span style="color: #60a5fa; font-weight: 600;">ALKALINE (9.18 ± 0.02)</span></small>
            </button>
        </div>

        <div id="hwSwitchAlert" style="display: none; margin-top: 14px; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; background: rgba(34,197,94,0.15); color: #86efac; border: 1px solid rgba(34,197,94,0.3);"></div>
    </section>

    <!-- Hardware Simulator & Telemetry Testing Console -->
    <section class="simulator-card">
        <div class="card-header">
            <div>
                <span class="section-kicker">Interactive Hardware Pre-Test</span>
                <h2>ESP32 Telemetry Simulator</h2>
                <p>Adjust probe parameters and transmit JSON telemetry packets directly to the authenticated REST API.</p>
            </div>
            <x-badge tone="blue">Firmware Ready</x-badge>
        </div>

        <div class="sim-grid">
            <!-- Soil pH Tile -->
            <div class="sim-tile">
                <div class="sim-tile-header">
                    <div class="sim-tile-info">
                        <span class="sim-tile-icon"><x-icon name="flask" :size="18" /></span>
                        <div>
                            <b>Soil pH</b>
                            <small>Analog probe · GPIO 34</small>
                        </div>
                    </div>
                    <span class="sim-value-pill" id="simPhDisplay">6.4</span>
                </div>
                <div class="sim-slider-wrap">
                    <label for="simPhSlider" class="sr-only">Simulated Soil pH (4.0 to 9.0)</label>
                    <input type="range" class="sim-slider" id="simPhSlider" min="4.0" max="9.0" step="0.1" value="6.4" aria-valuetext="6.4 pH">
                    <div class="sim-range-labels">
                        <span>4.0 Acidic</span>
                        <span>7.0 Neutral</span>
                        <span>9.0 Alkaline</span>
                    </div>
                </div>
            </div>

            <!-- Soil Moisture Tile -->
            <div class="sim-tile">
                <div class="sim-tile-header">
                    <div class="sim-tile-info">
                        <span class="sim-tile-icon"><x-icon name="droplet" :size="18" /></span>
                        <div>
                            <b>Soil Moisture</b>
                            <small>Capacitive v2 · GPIO 35</small>
                        </div>
                    </div>
                    <span class="sim-value-pill" id="simMoistureDisplay">35.0%</span>
                </div>
                <div class="sim-slider-wrap">
                    <label for="simMoistureSlider" class="sr-only">Simulated Soil Moisture Percentage (10% to 85%)</label>
                    <input type="range" class="sim-slider" id="simMoistureSlider" min="10" max="85" step="0.5" value="35" aria-valuetext="35.0%">
                    <div class="sim-range-labels">
                        <span>10% Wilting</span>
                        <span>50% Field Cap.</span>
                        <span>85% Saturation</span>
                    </div>
                </div>
            </div>

            <!-- Temperature Tile -->
            <div class="sim-tile">
                <div class="sim-tile-header">
                    <div class="sim-tile-info">
                        <span class="sim-tile-icon"><x-icon name="temp" :size="18" /></span>
                        <div>
                            <b>Temperature</b>
                            <small>DS18B20 OneWire · GPIO 4</small>
                        </div>
                    </div>
                    <span class="sim-value-pill" id="simTempDisplay">27.0°C</span>
                </div>
                <div class="sim-slider-wrap">
                    <label for="simTempSlider" class="sr-only">Simulated Soil Temperature in Celsius (15 to 42)</label>
                    <input type="range" class="sim-slider" id="simTempSlider" min="15" max="42" step="0.5" value="27" aria-valuetext="27.0°C">
                    <div class="sim-range-labels">
                        <span>15°C Cool</span>
                        <span>27°C Optimal</span>
                        <span>42°C Stress</span>
                    </div>
                </div>
            </div>

            <!-- EC Tile -->
            <div class="sim-tile">
                <div class="sim-tile-header">
                    <div class="sim-tile-info">
                        <span class="sim-tile-icon"><x-icon name="bolt" :size="18" /></span>
                        <div>
                            <b>Salinity (EC)</b>
                            <small>EC probe · GPIO 32</small>
                        </div>
                    </div>
                    <span class="sim-value-pill" id="simEcDisplay">1.20 mS/cm</span>
                </div>
                <div class="sim-slider-wrap">
                    <label for="simEcSlider" class="sr-only">Simulated Salinity EC in mS/cm (0.0 to 4.0)</label>
                    <input type="range" class="sim-slider" id="simEcSlider" min="0.0" max="4.0" step="0.05" value="1.2" aria-valuetext="1.20 mS/cm">
                    <div class="sim-range-labels">
                        <span>0.0 Low Salt</span>
                        <span>2.0 Limit</span>
                        <span>4.0 Saline</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="sim-footer">
            <div class="sim-sample-select">
                <label for="simSampleCode">
                    <x-icon name="flask" :size="15" />
                    <span>Target Sample:</span>
                </label>
                <input type="text" id="simSampleCode" value="SS-2025-018" placeholder="SS-2025-018">

                <div class="sim-presets">
                    <span class="sim-presets-label">Presets:</span>
                    <button type="button" class="sim-preset-btn" data-ph="6.5" data-moisture="60" data-temp="26" data-ec="1.2">Optimal Loam</button>
                    <button type="button" class="sim-preset-btn" data-ph="5.2" data-moisture="22" data-temp="34" data-ec="0.6">Acidic / Dry</button>
                    <button type="button" class="sim-preset-btn" data-ph="7.8" data-moisture="75" data-temp="31" data-ec="3.2">Saline / Wet</button>
                </div>
            </div>

            <div class="sim-actions">
                <button type="button" class="btn btn-primary" id="sendSimulatedPacketBtn">
                    <x-icon name="wifi" :size="16" />
                    <span>Send ESP32 Telemetry</span>
                </button>
            </div>
        </div>

        <!-- Feedback Banner -->
        <div id="simFeedbackBanner" hidden></div>

        <!-- Raw JSON Drawer (collapsible) -->
        <details id="simJsonDrawer" class="sim-json-drawer" hidden>
            <summary>View Ingested Payload &amp; Assessment Response (JSON)</summary>
            <div id="simResponseBox" class="sim-response-box"></div>
        </details>
    </section>

    <!-- Live Transmission History Log -->
    <section class="card transmission">
        <div class="card-header">
            <div>
                <span class="section-kicker">Recent activity</span>
                <h2>Transmission history</h2>
            </div>
            <select aria-label="Filter events" id="transmissionLogFilter">
                <option value="all">All events</option>
                <option value="warnings">Warnings only</option>
            </select>
        </div>

        <div id="transmissionLogList">
            @forelse($recentReadings as $logReading)
                @php
                    $paramCount = collect([
                        $logReading->ph,
                        $logReading->moisture,
                        $logReading->temperature,
                        $logReading->ec,
                    ])->filter(fn ($v) => $v !== null)->count();
                    $hasWarning = ($logReading->moisture_unit === 'raw index') || ($paramCount < 4) || !$logReading->is_valid;
                    $eventLabel = $logReading->is_valid
                        ? ucfirst($logReading->data_source ?? 'demo') . ' packet recorded (' . ($logReading->soilSample?->sample_code ?? 'Sample #' . $logReading->soil_sample_id) . ')'
                        : 'Packet flagged invalid';
                    $noteLabel = $logReading->moisture_unit === 'raw index'
                        ? "{$paramCount} readings stored · Moisture uncalibrated"
                        : "{$paramCount} of 4 channels stored";
                @endphp
                <div class="log-row" data-type="{{ $hasWarning ? 'warning' : 'info' }}">
                    <span class="log-dot {{ $hasWarning ? 'amber' : '' }}" aria-hidden="true"></span>
                    <b>{{ $logReading->recorded_at ? $logReading->recorded_at->format('g:i:s A') : 'N/A' }}</b>
                    <span>{{ $eventLabel }}</span>
                    <small>{{ $noteLabel }}</small>
                </div>
            @empty
                <div class="log-row" data-type="warning">
                    <span class="log-dot amber" aria-hidden="true"></span>
                    <b>No packets</b>
                    <span>No sensor transmissions recorded yet</span>
                    <small>Use the simulator above to send a packet</small>
                </div>
            @endforelse
        </div>
    </section>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const switcher = document.getElementById('stateSwitcher');
        const heroCard = document.getElementById('deviceHeroCard');
        const badge = document.getElementById('deviceHeroBadge');
        const text = document.getElementById('deviceHeroText');
        const actionBtn = document.getElementById('deviceHeroActionBtn');
        const apiPanel = document.getElementById('apiStatusPanel');
        const logList = document.getElementById('transmissionLogList');

        const states = {
            online: {
                className: 'device-hero card state-online',
                badgeClass: 'badge badge-green',
                badgeHtml: '<span class="badge-dot"></span>Online · preview',
                text: 'Previewing healthy online connection state.',
                btnText: 'View API details'
            },
            offline: {
                className: 'device-hero card state-offline',
                badgeClass: 'badge badge-gray',
                badgeHtml: '<span class="badge-dot"></span>Offline · preview',
                text: 'No recent communication. Last state is not assumed current.',
                btnText: 'Check API status'
            },
            error: {
                className: 'device-hero card state-error',
                badgeClass: 'badge badge-red',
                badgeHtml: '<span class="badge-dot"></span>Sensor error · preview',
                text: 'Moisture sensor returned an invalid raw value.',
                btnText: 'Inspect API panel'
            }
        };

        switcher.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', function () {
                switcher.querySelectorAll('button').forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-pressed', 'false');
                });
                this.classList.add('active');
                this.setAttribute('aria-pressed', 'true');

                const state = this.getAttribute('data-state');
                const conf = states[state];

                heroCard.className = conf.className;
                badge.className = conf.badgeClass;
                badge.innerHTML = conf.badgeHtml;
                text.textContent = conf.text;
                actionBtn.textContent = conf.btnText;
            });
        });

        actionBtn.addEventListener('click', function () {
            if (!apiPanel) return;
            apiPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            apiPanel.classList.add('highlight-ring');
            window.setTimeout(() => apiPanel.classList.remove('highlight-ring'), 1600);
        });

        const logFilter = document.getElementById('transmissionLogFilter');
        function applyLogFilter() {
            const val = logFilter.value;
            logList.querySelectorAll('.log-row').forEach(row => {
                row.style.display = (val === 'warnings' && row.getAttribute('data-type') !== 'warning') ? 'none' : 'grid';
            });
        }
        logFilter.addEventListener('change', applyLogFilter);

        const phSlider = document.getElementById('simPhSlider');
        const phDisplay = document.getElementById('simPhDisplay');
        const moistureSlider = document.getElementById('simMoistureSlider');
        const moistureDisplay = document.getElementById('simMoistureDisplay');
        const tempSlider = document.getElementById('simTempSlider');
        const tempDisplay = document.getElementById('simTempDisplay');
        const ecSlider = document.getElementById('simEcSlider');
        const ecDisplay = document.getElementById('simEcDisplay');
        const sendBtn = document.getElementById('sendSimulatedPacketBtn');
        const responseBox = document.getElementById('simResponseBox');
        const jsonDrawer = document.getElementById('simJsonDrawer');
        const feedbackBanner = document.getElementById('simFeedbackBanner');
        const sampleCodeInput = document.getElementById('simSampleCode');

        function updateDisplays() {
            if (phSlider) {
                const v = parseFloat(phSlider.value).toFixed(1);
                phDisplay.textContent = v;
                phSlider.setAttribute('aria-valuetext', `${v} pH`);
            }
            if (moistureSlider) {
                const v = parseFloat(moistureSlider.value).toFixed(1) + '%';
                moistureDisplay.textContent = v;
                moistureSlider.setAttribute('aria-valuetext', v);
            }
            if (tempSlider) {
                const v = parseFloat(tempSlider.value).toFixed(1) + '°C';
                tempDisplay.textContent = v;
                tempSlider.setAttribute('aria-valuetext', v);
            }
            if (ecSlider) {
                const v = parseFloat(ecSlider.value).toFixed(2) + ' mS/cm';
                ecDisplay.textContent = v;
                ecSlider.setAttribute('aria-valuetext', v);
            }
        }

        function prependLogEntry(isError, label, detail) {
            if (!logList) return;
            const nowStr = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
            const row = document.createElement('div');
            row.className = 'log-row';
            row.setAttribute('data-type', isError ? 'warning' : 'info');
            row.innerHTML = `<span class="log-dot ${isError ? 'amber' : ''}" aria-hidden="true"></span><b>${nowStr}</b><span></span><small></small>`;
            row.querySelector('span:nth-of-type(2)').textContent = label;
            row.querySelector('small').textContent = detail;
            logList.prepend(row);
            applyLogFilter();
        }

        if (phSlider) {
            phSlider.addEventListener('input', updateDisplays);
            moistureSlider.addEventListener('input', updateDisplays);
            tempSlider.addEventListener('input', updateDisplays);
            ecSlider.addEventListener('input', updateDisplays);

            document.querySelectorAll('.sim-preset-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    phSlider.value = this.getAttribute('data-ph');
                    moistureSlider.value = this.getAttribute('data-moisture');
                    tempSlider.value = this.getAttribute('data-temp');
                    ecSlider.value = this.getAttribute('data-ec');
                    updateDisplays();
                });
            });

            sendBtn.addEventListener('click', async function () {
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<span>Transmitting...</span>';
                feedbackBanner.hidden = true;

                const payload = {
                    sample_id: sampleCodeInput.value.trim() || 'SS-2025-018',
                    device_id: 'ESP32-01',
                    ph: parseFloat(phSlider.value),
                    moisture_percent: parseFloat(moistureSlider.value),
                    temperature_c: parseFloat(tempSlider.value),
                    ec_ms_cm: parseFloat(ecSlider.value)
                };

                try {
                    const res = await fetch('/api/v1/readings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'Authorization': 'Bearer demo-esp32-token-2025'
                        },
                        body: JSON.stringify(payload)
                    });

                    const json = await res.json();

                    if (res.ok) {
                        feedbackBanner.className = 'sim-feedback-toast success';
                        feedbackBanner.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span>Telemetry Ingested! Sample <b>${payload.sample_id}</b> updated to <b>${json.data?.assessment?.status ?? 'reviewed'}</b>.</span>`;
                        feedbackBanner.hidden = false;
                        prependLogEntry(false, `Simulated packet accepted (${payload.sample_id})`, '4 of 4 calibrated channels stored');
                    } else {
                        feedbackBanner.className = 'sim-feedback-toast error';
                        feedbackBanner.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span>Transmission Rejected: ${json.error || 'Validation error'}</span>`;
                        feedbackBanner.hidden = false;
                        prependLogEntry(true, `Packet rejected (${payload.sample_id})`, json.error || `HTTP ${res.status}`);
                    }

                    if (jsonDrawer) {
                        jsonDrawer.hidden = false;
                        jsonDrawer.open = true;
                    }
                    responseBox.textContent = `// HTTP ${res.status} Response\n` + JSON.stringify(json, null, 2);
                    responseBox.style.color = res.ok ? '#4ade80' : '#f87171';
                } catch (err) {
                    feedbackBanner.className = 'sim-feedback-toast error';
                    feedbackBanner.textContent = `Network Error: ${err.message}`;
                    feedbackBanner.hidden = false;
                } finally {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg><span>Send ESP32 Telemetry</span>';
                }
            });
        }
        // --- Hardware Soil Sample Remote Switcher Logic ---
        const hwSampleBtns = document.querySelectorAll('.hw-sample-btn');
        const activeSampleText = document.getElementById('currentActiveSampleText');
        const hwSwitchAlert = document.getElementById('hwSwitchAlert');

        function highlightActiveHwButton(activeCode) {
            hwSampleBtns.forEach(btn => {
                const code = btn.getAttribute('data-code');
                if (code === activeCode) {
                    btn.style.boxShadow = '0 0 0 2px #22c55e, 0 4px 12px rgba(34, 197, 94, 0.35)';
                    btn.style.borderColor = '#22c55e';
                } else {
                    btn.style.boxShadow = 'none';
                    btn.style.borderColor = btn.getAttribute('data-code') === 'S1' ? 'rgba(239,68,68,0.4)' : (btn.getAttribute('data-code') === 'S2' ? 'rgba(34,197,94,0.4)' : 'rgba(59,130,246,0.4)');
                }
            });
        }

        // Highlight initial active button
        const initialActive = '{{ $activeSample['sample_code'] ?? 'S1' }}';
        highlightActiveHwButton(initialActive);

        hwSampleBtns.forEach(btn => {
            btn.addEventListener('click', async function () {
                const code = this.getAttribute('data-code');
                const targetPh = this.getAttribute('data-ph');
                const quality = this.getAttribute('data-quality');

                // Visual button click feedback
                this.style.opacity = '0.6';
                if (hwSwitchAlert) {
                    hwSwitchAlert.style.display = 'block';
                    hwSwitchAlert.innerHTML = `<span>⏳ Dispatching command to ESP32 over WiFi...</span>`;
                }

                try {
                    const res = await fetch('/api/v1/device/active-sample', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ sample_code: code })
                    });

                    const data = await res.json();

                    if (res.ok && data.success) {
                        highlightActiveHwButton(code);
                        if (activeSampleText) {
                            activeSampleText.textContent = `${data.active_sample.sample_code} (${data.active_sample.name})`;
                        }
                        if (hwSwitchAlert) {
                            hwSwitchAlert.innerHTML = `<span style="display: flex; align-items: center; gap: 8px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                <span><b>Command Dispatched to ESP32!</b> Active hardware target set to <b>${data.active_sample.name}</b> (Target: <b>${targetPh} pH</b> · <b>${quality}</b>). The physical 16x2 LCD screen will update now.</span>
                            </span>`;
                        }
                    } else {
                        if (hwSwitchAlert) {
                            hwSwitchAlert.innerHTML = `<span style="color: #f87171;">⚠️ Failed to dispatch command: ${data.error || 'Server error'}</span>`;
                        }
                    }
                } catch (e) {
                    if (hwSwitchAlert) {
                        hwSwitchAlert.innerHTML = `<span style="color: #f87171;">⚠️ Connection error: ${e.message}</span>`;
                    }
                } finally {
                    this.style.opacity = '1';
                }
            });
        });
    });
</script>
@endpush
