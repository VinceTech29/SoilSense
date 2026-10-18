@extends('layouts.app', ['title' => 'Overview'])

@section('content')
    <!-- Page Title & Header Status -->
    <div class="page-title">
        <div>
            <h1>Soil Overview</h1>
            <p>Monitor measured conditions and evaluate crop candidates with transparent evidence.</p>
        </div>
        <div class="updated">
            <span class="status-pulse"></span>
            <div>
                <b>Latest sample</b>
                <small>{{ $latestSample ? $latestSample->collected_at->format('M d, Y · g:i A') : 'No samples recorded' }}</small>
            </div>
        </div>
    </div>

    <!-- Demonstration Notice -->
    <div class="notice demo">
        <x-icon name="flask" />
        <div>
            <b>Demonstration dataset</b>
            <span>Values shown are illustrative prototype data, not live ESP32 readings or a certified laboratory analysis.</span>
        </div>
        <a href="{{ route('device') }}">Learn about data quality</a>
    </div>

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
                {{ ucfirst($device?->status ?? 'online') }} · {{ $device?->is_demo ? 'Demo' : 'Live' }}
            </x-badge>
        </div>
        <div class="device-detail">
            <span>Last received</span>
            <b>{{ $device && $device->last_seen_at ? $device->last_seen_at->diffForHumans() : '2 minutes ago' }}</b>
        </div>
        <div class="device-detail">
            <span>Sensor status</span>
            <b>{{ $assessment?->usable_parameters_count ?? 3 }} of 4 reporting</b>
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
                    <span class="section-kicker">Current sample · {{ $latestSample?->sample_code ?? 'SS-2025-018' }}</span>
                    <h2>Preliminary assessment</h2>
                </div>
                <x-badge :tone="($assessment?->status ?? 'preliminary') === 'reviewed' ? 'green' : 'amber'">
                    {{ ucfirst($assessment?->status ?? 'Incomplete evidence') }}
                </x-badge>
            </div>
            
            <p>{{ $assessment?->summary ?? 'Three measurements are available. The sample’s pH aligns with several candidate crop ranges, but moisture is uncalibrated and EC is missing.' }}</p>

            <div class="completeness">
                <div class="ring">
                    <b>{{ $assessment?->usable_parameters_count ?? 3 }}/4</b>
                    <span>parameters</span>
                </div>
                <div class="check-list">
                    <p>
                        @if($reading && $reading->ph !== null)
                            <span class="check-ok"><x-icon name="check" :size="14" /></span> pH reading available ({{ $reading->ph }})
                        @else
                            <span class="check-miss">—</span> pH not recorded
                        @endif
                    </p>
                    <p>
                        @if($reading && $reading->temperature !== null)
                            <span class="check-ok"><x-icon name="check" :size="14" /></span> Temperature available ({{ $reading->temperature }}°C)
                        @else
                            <span class="check-miss">—</span> Temperature not recorded
                        @endif
                    </p>
                    <p>
                        @if($reading && $reading->moisture !== null)
                            @if($reading->moisture_unit === 'raw index')
                                <span class="check-warn">!</span> Moisture uncalibrated ({{ $reading->moisture }} raw)
                            @else
                                <span class="check-ok"><x-icon name="check" :size="14" /></span> Moisture calibrated ({{ $reading->moisture }}%)
                            @endif
                        @else
                            <span class="check-miss">—</span> Moisture missing
                        @endif
                    </p>
                    <p>
                        @if($reading && $reading->ec !== null)
                            <span class="check-ok"><x-icon name="check" :size="14" /></span> EC available ({{ $reading->ec }} mS/cm)
                        @else
                            <span class="check-miss">—</span> EC not measured
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

            <a href="{{ route('report', ['id' => $latestSample?->sample_code]) }}" class="btn btn-primary" style="width: 100%;">
                View full assessment
            </a>
        </section>

        <!-- Trend Card -->
        <section class="card trend">
            <div class="card-header">
                <div>
                    <span class="section-kicker">Historical readings</span>
                    <h2>Soil pH trend</h2>
                </div>
                <select aria-label="Date range" id="trendRangeSelect">
                    <option value="14">Last 14 days</option>
                    <option value="30">Last 30 days</option>
                </select>
            </div>

            <div class="trend-summary">
                <b>{{ $reading && $reading->ph !== null ? number_format($reading->ph, 1) : '6.2' }}</b>
                <span>Latest pH</span>
                <small>+0.2 from previous sample</small>
            </div>

            <div class="chart-wrap">
                <div class="chart-y">
                    <span>7.0</span>
                    <span>6.5</span>
                    <span>6.0</span>
                    <span>5.5</span>
                </div>
                <svg class="trend-chart" viewBox="0 0 700 190" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="fade" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#22c55e" stop-opacity=".18"/>
                            <stop offset="1" stop-color="#22c55e" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path class="gridline" d="M0 20H700M0 70H700M0 120H700M0 170H700"/>
                    <path class="area" d="M0 133 C60 125 80 90 140 98 S220 116 280 82 S350 55 420 72 S500 108 560 70 S640 46 700 51 V190H0Z"/>
                    <path class="line" d="M0 133 C60 125 80 90 140 98 S220 116 280 82 S350 55 420 72 S500 108 560 70 S640 46 700 51"/>
                    <circle cx="700" cy="51" r="5"/>
                </svg>
                <div class="chart-x">
                    <span>May 08</span>
                    <span>May 10</span>
                    <span>May 12</span>
                    <span>May 14</span>
                    <span>May 16</span>
                    <span>Today</span>
                </div>
            </div>

            <div class="legend">
                <span><i class="line-key"></i> Measured pH</span>
                <span><i class="band-key"></i> Candidate crop range</span>
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
                        <th>Date & time</th>
                        <th>Location</th>
                        <th>pH</th>
                        <th>Moisture</th>
                        <th>Temperature</th>
                        <th>EC</th>
                        <th>Assessment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($samples as $sample)
                        <tr onclick="window.location='{{ route('report', ['id' => $sample['id']]) }}'" style="cursor: pointer;">
                            <td><b>{{ $sample['id'] }}</b></td>
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
                                <a href="{{ route('report', ['id' => $sample['id']]) }}" aria-label="View sample">
                                    <x-icon name="chevron" :size="16" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <span>Showing 1–{{ count($samples) }} of 18 samples</span>
            <div>
                <a href="{{ route('history') }}" class="btn btn-secondary">See all in History</a>
            </div>
        </div>
    </section>
@endsection
