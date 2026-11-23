@extends('layouts.app', ['title' => 'Overview'])

@section('content')
    @php
        $usableCount = (int) ($assessment?->usable_parameters_count ?? 0);
        $totalCount = (int) ($assessment?->total_parameters_count ?? 4);
        $ringPct = $totalCount > 0 ? round(($usableCount / $totalCount) * 100) : 0;
        $isDemo = $latestSample ? $latestSample->source_type === 'demo' : true;
        $phLabels = $phTrendSeries['14']['labels'] ?? [];
        $hasPhTrend = !empty($phLabels) || !empty($phTrendSeries['30']['labels']);
    @endphp

    <!-- Page Title & Header Status -->
    <div class="page-title">
        <div>
            <h1>Soil Overview</h1>
            <p>Monitor measured conditions and evaluate crop candidates with transparent evidence.</p>
        </div>
        <div class="updated">
            <span class="status-pulse {{ $latestSample ? '' : 'offline' }}" aria-hidden="true"></span>
            <div>
                <b>Latest sample</b>
                <small>{{ $latestSample ? $latestSample->collected_at->format('M d, Y · g:i A') : 'No samples recorded' }}</small>
            </div>
        </div>
    </div>

    <!-- Demonstration Notice -->
    @if($isDemo)
        <div class="notice demo">
            <x-icon name="flask" />
            <div>
                <b>Demonstration dataset active</b>
                <span>Values shown include simulated prototype data, not a certified laboratory soil certificate.</span>
            </div>
            <a href="{{ route('device') }}">Learn about data quality</a>
        </div>
    @endif

    <!-- Device Telemetry Strip -->
    <section class="device-strip card">
        <div class="device-id">
            <span class="device-icon"><x-icon name="cpu" :size="24" /></span>
            <div>
                <span>IoT device</span>
                <b>{{ $device?->device_uid ?? 'ESP32-01' }}</b>
            </div>
        </div>
        <div class="device-detail">
            <span>Connection</span>
            <x-badge :tone="$device && $device->status === 'online' ? 'green' : 'gray'">
                {{ ucfirst($device?->status ?? 'offline') }} · {{ $device?->is_demo ? 'Demo' : 'Live' }}
            </x-badge>
        </div>
        <div class="device-detail">
            <span>Last received</span>
            <b>{{ $device && $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Awaiting packet' }}</b>
        </div>
        <div class="device-detail">
            <span>Sensor status</span>
            <b>{{ $usableCount }} of {{ $totalCount }} reporting</b>
        </div>
        <a href="{{ route('device') }}" class="btn btn-secondary">View device details</a>
    </section>

    <!-- Metrics Grid -->
    <div class="metrics-grid">
        @foreach($measurements as $item)
            <x-measurement-card :item="$item" />
        @endforeach
    </div>

    <!-- Assessment & Trends Grid -->
    <div class="dashboard-grid">
        <!-- Assessment Card -->
        <section class="card assessment">
            <div class="card-header">
                <div>
                    <span class="section-kicker">
                        {{ $latestSample ? 'Current sample · ' . $latestSample->sample_code : 'No active sample' }}
                    </span>
                    <h2>{{ $assessment ? ucfirst($assessment->status) . ' assessment' : 'Awaiting assessment' }}</h2>
                </div>
                <x-badge :tone="($assessment?->status ?? 'incomplete') === 'reviewed' ? 'green' : 'amber'">
                    {{ ucfirst($assessment?->status ?? 'No data') }}
                </x-badge>
            </div>
            <p>{{ $assessment?->summary ?? 'No soil assessment is available yet. Register a sample or transmit telemetry to begin evaluation.' }}</p>

            <div class="completeness">
                <div class="ring" style="--pct: {{ $ringPct }};">
                    <b>{{ $usableCount }}/{{ $totalCount }}</b>
                    <span>parameters</span>
                </div>
                <div class="check-list">
                    <p>
                        @if($reading && $reading->ph !== null)
                            <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="14" /></span> pH reading available ({{ number_format($reading->ph, 1) }})
                        @else
                            <span class="check-miss" aria-hidden="true">-</span> pH not recorded
                        @endif
                    </p>
                    <p>
                        @if($reading && $reading->temperature !== null)
                            <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="14" /></span> Temperature available ({{ round($reading->temperature) }}°C)
                        @else
                            <span class="check-miss" aria-hidden="true">-</span> Temperature not recorded
                        @endif
                    </p>
                    <p>
                        @if($reading && $reading->moisture !== null)
                            @if($reading->moisture_unit === 'raw index')
                                <span class="check-warn" aria-hidden="true">!</span> Moisture uncalibrated ({{ $reading->moisture }} raw)
                            @else
                                <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="14" /></span> Moisture calibrated ({{ $reading->moisture }}%)
                            @endif
                        @else
                            <span class="check-miss" aria-hidden="true">-</span> Moisture missing
                        @endif
                    </p>
                    <p>
                        @if($reading && $reading->ec !== null)
                            <span class="check-ok" aria-hidden="true"><x-icon name="check" :size="14" /></span> EC available ({{ $reading->ec }} mS/cm)
                        @else
                            <span class="check-miss" aria-hidden="true">-</span> EC not measured
                        @endif
                    </p>
                </div>
            </div>

            <div class="callout">
                <x-icon name="warning" />
                <span>
                    <b>What this assessment cannot establish</b>
                    {{ $assessment?->limitations ?? 'Soil nutrients, texture, drainage, organic matter, and field climate are not assessed.' }}
                </span>
            </div>

            @if($latestSample)
                <a href="{{ route('report', ['id' => $latestSample->sample_code]) }}" class="btn btn-primary btn-block">
                    View full assessment
                </a>
            @else
                <a href="{{ route('analysis') }}" class="btn btn-primary btn-block">
                    Register first soil sample
                </a>
            @endif
        </section>

        <!-- Real Soil pH Trend Card -->
        <section class="card trend">
            <div class="card-header">
                <div>
                    <span class="section-kicker">Historical readings</span>
                    <h2>Soil pH trend</h2>
                </div>
                <select aria-label="Trend time window" id="trendRangeSelect">
                    <option value="14">Last 14 days</option>
                    <option value="30">Last 30 days</option>
                </select>
            </div>

            <div class="trend-summary">
                <b>{{ $reading && $reading->ph !== null ? number_format($reading->ph, 1) : 'Pending' }}</b>
                <span>Latest measured pH</span>
                <small id="dashboardTrendDelta">{{ $phTrendSeries['14']['delta_text'] ?? 'Recorded sample history' }}</small>
            </div>

            @if($hasPhTrend)
                <div class="dashboard-chart-box">
                    <canvas id="dashboardPhChart" data-series='@json($phTrendSeries)' aria-label="Historical soil pH trend chart" role="img"></canvas>
                </div>
            @else
                <div class="empty-state-box" style="margin-top: 16px;">
                    <h3>No pH history yet</h3>
                    <p>Record two or more samples with soil pH measurements to view historical trend lines.</p>
                    <a href="{{ route('analysis') }}" class="btn btn-secondary">Record soil sample</a>
                </div>
            @endif

            <div class="legend">
                <span><i class="line-key" aria-hidden="true"></i> Measured pH across recorded samples</span>
            </div>
        </section>
    </div>

    <!-- Crop Recommendations Preview Block -->
    <section class="section-block">
        <div class="section-title">
            <div>
                <span class="section-kicker">Rule-based analysis</span>
                <h2>Crop suitability preview</h2>
                <p>Matches use available readings only. Evidence remains incomplete until key soil tests are added.</p>
            </div>
            <a href="{{ route('crops') }}" class="btn btn-secondary">View all crops</a>
        </div>

        <div class="crop-grid">
            @foreach(array_slice($crops, 0, 3) as $crop)
                <x-crop-card :crop="$crop" />
            @endforeach
        </div>
    </section>

    <!-- Recent Samples Table -->
    <section class="card table-card">
        <div class="section-title table-title">
            <div>
                <span class="section-kicker">Sample records</span>
                <h2>Recent soil samples</h2>
            </div>
            <div class="table-tools">
                <a href="{{ route('history') }}" class="btn btn-secondary">View history</a>
            </div>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Sample ID</th>
                        <th>Date &amp; time</th>
                        <th>Location</th>
                        <th>pH</th>
                        <th>Moisture</th>
                        <th>Temperature</th>
                        <th>EC</th>
                        <th>Assessment</th>
                        <th>Report</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($samples as $sample)
                        <tr>
                            <td>
                                <a href="{{ route('report', ['id' => $sample['id']]) }}">
                                    <b>{{ $sample['id'] }}</b>
                                </a>
                            </td>
                            <td>{{ $sample['date'] }}</td>
                            <td>{{ $sample['location'] }}</td>
                            <td>{{ $sample['ph'] }}</td>
                            <td>{{ $sample['moisture'] }}</td>
                            <td>{{ $sample['temperature'] }}</td>
                            <td>{{ $sample['ec'] }}</td>
                            <td>
                                <x-badge :tone="$sample['assessment'] === 'Reviewed' ? 'green' : 'amber'">
                                    {{ $sample['assessment'] }}
                                </x-badge>
                            </td>
                            <td>
                                <a href="{{ route('report', ['id' => $sample['id']]) }}" aria-label="View report for {{ $sample['id'] }}">
                                    <span>View</span>
                                    <x-icon name="chevron" :size="15" />
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 24px; color: var(--muted);">
                                No soil samples recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <span>Showing {{ count($samples) > 0 ? '1–' . count($samples) : '0' }} of {{ $totalSamplesCount ?? count($samples) }} samples</span>
            <div>
                <a href="{{ route('history') }}" class="btn btn-secondary">See all in History</a>
            </div>
        </div>
    </section>
@endsection
