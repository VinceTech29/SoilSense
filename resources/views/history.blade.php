@extends('layouts.app', ['title' => 'Sample History'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Sample History</h1>
            <p>Search, review, and compare soil samples with their quality and provenance intact.</p>
        </div>
        <button type="button" class="btn btn-secondary" id="compareSamplesBtn" disabled>Compare samples (0/2)</button>
    </div>

    <!-- Filter Bar with Form Support -->
    <form method="GET" action="{{ route('history') }}" class="filterbar card">
        <label class="search wide">
            <x-icon name="search" :size="18" />
            <input type="text" name="search" id="historySearchInput" value="{{ request('search') }}" placeholder="Search sample ID, name, or location">
        </label>
        <input type="date" name="date" aria-label="Start date" id="historyDateFilter" value="{{ request('date') }}">
        <select name="status" id="historyStatusFilter" aria-label="Assessment state">
            <option value="All" {{ request('status') === 'All' ? 'selected' : '' }}>All assessment states</option>
            <option value="Preliminary" {{ request('status') === 'Preliminary' ? 'selected' : '' }}>Preliminary</option>
            <option value="Reviewed" {{ request('status') === 'Reviewed' ? 'selected' : '' }}>Reviewed</option>
            <option value="Incomplete" {{ request('status') === 'Incomplete' ? 'selected' : '' }}>Incomplete</option>
        </select>
        <button type="submit" class="btn btn-secondary" id="applyHistoryFilter">Filter</button>
        @if(request()->hasAny(['search', 'date', 'status']))
            <a href="{{ route('history') }}" class="btn btn-ghost">Reset</a>
        @endif
    </form>

    <!-- 4-Sensor Telemetry Trend Chart -->
    @php
        $trendPayload = $sensorTrend ?? [
            'labels' => [],
            'ph' => [],
            'ec' => [],
            'moisture' => [],
            'temperature' => [],
            'has_data' => false,
        ];
    @endphp
    <section class="card sensor-trend-card" id="sensorTrendSection">
        <div class="sensor-trend-head">
            <div>
                <span class="section-kicker">4-sensor telemetry trends</span>
                <h2>Soil Sensor Trend Lines (pH, EC, Moisture &amp; Temperature)</h2>
                <p>Chronological 4-sensor measurements across recorded samples: pH and EC (mS/cm) on the left axis; Moisture (%) and Temperature (°C) on the right axis.</p>
            </div>
            <div class="sensor-pills">
                <span class="sensor-pill"><i class="dot-ph"></i> Soil pH</span>
                <span class="sensor-pill"><i class="dot-ec"></i> EC (mS/cm)</span>
                <span class="sensor-pill"><i class="dot-moisture"></i> Moisture (%)</span>
                <span class="sensor-pill"><i class="dot-temp"></i> Temperature (°C)</span>
            </div>
        </div>

        @if($trendPayload['has_data'])
            <div class="sensor-chart-container">
                <canvas id="sensorTrendChart" data-trend='@json($trendPayload)' aria-label="Soil 4-sensor telemetry trend chart" role="img"></canvas>
            </div>
        @else
            <div class="fert-empty-state" style="margin-bottom: 0;">
                <canvas id="sensorTrendChart" data-trend='@json($trendPayload)' style="display: none;"></canvas>
                <p style="margin: 0;">No sensor telemetry readings are recorded for the current filter. Register a new soil sample to view 4-sensor trend lines.</p>
            </div>
        @endif
    </section>

    <!-- History Samples Table -->
    <section class="card table-card history-table">
        <div class="table-scroll">
            <table id="historyTable">
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Sample</th>
                        <th>Collected</th>
                        <th>Location</th>
                        <th>Device</th>
                        <th>Valid data</th>
                        <th>Assessment</th>
                        <th>Candidate crops</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($samples as $sample)
                        <tr data-id="{{ $sample['id'] }}">
                            <td>
                                <input type="checkbox" class="sample-checkbox" value="{{ $sample['id'] }}">
                            </td>
                            <td>
                                <b>{{ $sample['id'] }}</b>
                                <small>{{ $sample['name'] }}</small>
                            </td>
                            <td>{{ $sample['date'] }}</td>
                            <td>{{ $sample['location'] }}</td>
                            <td>{{ $sample['device'] }}</td>
                            <td><b>{{ $sample['valid_count'] }}</b></td>
                            <td>
                                <x-badge :tone="$sample['assessment'] === 'Reviewed' ? 'green' : 'amber'">
                                    {{ $sample['assessment'] }}
                                </x-badge>
                            </td>
                            <td>{{ $sample['crops'] }}</td>
                            <td>
                                <a href="{{ route('report', ['id' => $sample['id']]) }}" class="btn btn-ghost">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 24px; color: var(--muted);">
                                No soil samples matched the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($paginator) && $paginator->hasPages())
            <div class="pagination">
                <span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }} samples</span>
                <div>
                    @if ($paginator->onFirstPage())
                        <button type="button" class="btn btn-ghost" disabled>Previous</button>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" class="btn btn-ghost">Previous</a>
                    @endif

                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" class="btn btn-secondary">Next</a>
                    @else
                        <button type="button" class="btn btn-secondary" disabled>Next</button>
                    @endif
                </div>
            </div>
        @else
            <div class="pagination">
                <span>Showing {{ count($samples) }} samples</span>
            </div>
        @endif
    </section>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const checkboxes = document.querySelectorAll('.sample-checkbox');
        const compareBtn = document.getElementById('compareSamplesBtn');

        let selected = [];

        checkboxes.forEach(cb => {
            cb.addEventListener('change', function () {
                if (this.checked) {
                    if (selected.length < 2) {
                        selected.push(this.value);
                    } else {
                        this.checked = false;
                        alert('You can compare a maximum of 2 samples side by side.');
                    }
                } else {
                    selected = selected.filter(id => id !== this.value);
                }

                compareBtn.textContent = `Compare samples (${selected.length}/2)`;
                compareBtn.disabled = selected.length !== 2;
            });
        });

        compareBtn.addEventListener('click', function () {
            if (selected.length === 2) {
                window.location.href = `{{ url('/report') }}/${selected[0]}`;
            }
        });
    });
</script>
@endpush
