@extends('layouts.app', ['title' => 'Sample History'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Sample History</h1>
            <p>Search, review, and compare soil samples side by side with quality and provenance intact.</p>
        </div>
        <button type="button" class="btn btn-secondary" id="compareSamplesBtn" disabled>Compare samples (0/2)</button>
    </div>

    <!-- Filter Bar with Form Support -->
    <form method="GET" action="{{ route('history') }}" class="filterbar card">
        <label class="search wide">
            <x-icon name="search" :size="18" />
            <input type="text" name="search" id="historySearchInput" aria-label="Search sample ID, name, or location" value="{{ request('search') }}" placeholder="Search sample ID, name, or location">
        </label>
        <input type="date" name="date" aria-label="Collection date" title="Filter by collection date" id="historyDateFilter" value="{{ request('date') }}">
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
                <span class="sensor-pill"><i class="dot-ph" aria-hidden="true"></i> Soil pH</span>
                <span class="sensor-pill"><i class="dot-ec" aria-hidden="true"></i> EC (mS/cm)</span>
                <span class="sensor-pill"><i class="dot-moisture" aria-hidden="true"></i> Moisture (%)</span>
                <span class="sensor-pill"><i class="dot-temp" aria-hidden="true"></i> Temperature (°C)</span>
            </div>
        </div>

        @if($trendPayload['has_data'])
            <div class="sensor-chart-container">
                <canvas id="sensorTrendChart" data-trend='@json($trendPayload)' aria-label="Soil 4-sensor telemetry trend chart" role="img"></canvas>
            </div>
        @else
            <div class="fert-empty-state" style="margin-bottom: 0;">
                <canvas id="sensorTrendChart" data-trend='@json($trendPayload)' hidden></canvas>
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
                        <th style="width: 48px;"><span class="sr-only">Compare</span></th>
                        <th>Sample</th>
                        <th>Collected</th>
                        <th>Location</th>
                        <th>Device</th>
                        <th>Valid data</th>
                        <th>Assessment</th>
                        <th>Candidate crops</th>
                        <th>Report</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($samples as $sample)
                        <tr data-id="{{ $sample['id'] }}"
                            data-sample='@json($sample)'
                            data-report-url="{{ route('report', ['id' => $sample['id']]) }}">
                            <td>
                                <input type="checkbox"
                                       class="sample-checkbox"
                                       value="{{ $sample['id'] }}"
                                       aria-label="Select sample {{ $sample['id'] }} for side-by-side comparison"
                                       style="width: 18px; height: 18px; accent-color: var(--green-700);">
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
                                <a href="{{ route('report', ['id' => $sample['id']]) }}" class="btn btn-ghost btn-compact">View</a>
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

    <!-- Side-by-Side Sample Comparison Modal -->
    <div class="compare-modal-backdrop" id="sampleCompareModal" hidden role="dialog" aria-modal="true" aria-labelledby="sampleCompareTitle">
        <div class="compare-modal">
            <div class="compare-modal-head">
                <div>
                    <span class="section-kicker">Side-by-side review</span>
                    <h2 id="sampleCompareTitle">Soil Sample Comparison</h2>
                </div>
                <button type="button" class="btn btn-ghost" id="closeSampleCompareBtn" aria-label="Close comparison modal">Close</button>
            </div>
            <div class="table-scroll">
                <table id="sampleCompareTable" style="white-space: normal;"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const checkboxes = document.querySelectorAll('.sample-checkbox');
        const compareBtn = document.getElementById('compareSamplesBtn');
        const modal = document.getElementById('sampleCompareModal');
        const closeBtn = document.getElementById('closeSampleCompareBtn');
        const compareTable = document.getElementById('sampleCompareTable');

        let selectedRows = [];

        checkboxes.forEach(cb => {
            cb.addEventListener('change', function () {
                const row = this.closest('tr');
                if (this.checked) {
                    if (selectedRows.length < 2) {
                        selectedRows.push(row);
                    } else {
                        this.checked = false;
                        if (window.SoilSenseUI) {
                            window.SoilSenseUI.toast('You can compare a maximum of 2 samples side by side.', 'warning');
                        }
                    }
                } else {
                    selectedRows = selectedRows.filter(r => r !== row);
                }

                compareBtn.textContent = `Compare samples (${selectedRows.length}/2)`;
                compareBtn.disabled = selectedRows.length !== 2;
            });
        });

        function openModal() {
            if (selectedRows.length !== 2) return;
            const s1 = JSON.parse(selectedRows[0].dataset.sample);
            const s2 = JSON.parse(selectedRows[1].dataset.sample);
            const url1 = selectedRows[0].dataset.reportUrl;
            const url2 = selectedRows[1].dataset.reportUrl;

            const fields = [
                ['Sample Name', s1.name, s2.name],
                ['Collected', s1.date, s2.date],
                ['Location', s1.location, s2.location],
                ['Soil pH', s1.ph, s2.ph],
                ['Soil Moisture', s1.moisture, s2.moisture],
                ['Soil Temperature', s1.temperature, s2.temperature],
                ['Salinity (EC)', s1.ec, s2.ec],
                ['Recommended Fertilizer', s1.fertilizer || 'Not generated', s2.fertilizer || 'Not generated'],
                ['Valid Data', s1.valid_count, s2.valid_count],
                ['Assessment Status', s1.assessment, s2.assessment],
                ['Candidate Crops', s1.crops, s2.crops],
            ];

            const tbody = fields.map(([label, v1, v2]) => `<tr><th>${label}</th><td>${v1}</td><td>${v2}</td></tr>`).join('');
            const footerRow = `<tr><th>Full Report</th><td><a href="${url1}" class="btn btn-secondary btn-compact">Open ${s1.id}</a></td><td><a href="${url2}" class="btn btn-secondary btn-compact">Open ${s2.id}</a></td></tr>`;

            compareTable.innerHTML = `<thead><tr><th>Field</th><th>${s1.id}</th><th>${s2.id}</th></tr></thead><tbody>${tbody}${footerRow}</tbody>`;
            modal.hidden = false;
            closeBtn.focus();
        }

        function closeModal() {
            modal.hidden = true;
            compareBtn.focus();
        }

        compareBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });
    });
</script>
@endpush
