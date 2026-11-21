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

    <!-- N-P-K Macronutrient Trend Chart -->
    @php
        $trendPayload = $npkTrend ?? [
            'labels' => [],
            'nitrogen' => [],
            'phosphorus' => [],
            'potassium' => [],
            'has_data' => false,
        ];
    @endphp
    <section class="card npk-trend-card" id="npkTrendSection">
        <div class="npk-trend-head">
            <div>
                <span class="section-kicker">Macronutrient trends</span>
                <h2>Soil N-P-K Trend Lines (mg/kg)</h2>
                <p>Chronological Nitrogen (N), Phosphorus (P), and Potassium (K) concentrations across recorded soil samples.</p>
            </div>
            <div class="npk-pills">
                <span class="npk-pill"><i class="dot-n"></i> Nitrogen (N)</span>
                <span class="npk-pill"><i class="dot-p"></i> Phosphorus (P)</span>
                <span class="npk-pill"><i class="dot-k"></i> Potassium (K)</span>
            </div>
        </div>

        @if($trendPayload['has_data'])
            <div class="npk-chart-container">
                <canvas id="npkTrendChart" data-trend='@json($trendPayload)' aria-label="Soil N-P-K concentration trend chart" role="img"></canvas>
            </div>
        @else
            <div class="npk-empty-state" style="margin-bottom: 0;">
                <canvas id="npkTrendChart" data-trend='@json($trendPayload)' style="display: none;"></canvas>
                <p style="margin: 0;">No N-P-K macronutrient readings are recorded for the current filter. Enter N-P-K values on any assessment report or new soil sample to view trend lines.</p>
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
                        <th>N-P-K (mg/kg)</th>
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
                                <b>{{ $sample['npk_summary'] ?? 'Not recorded' }}</b>
                                @if(!empty($sample['npk_source']))
                                    <small>{{ ucfirst($sample['npk_source']) }}{{ !empty($sample['fertilizer']) ? ' · ' . $sample['fertilizer'] : '' }}</small>
                                @else
                                    <small>No N-P-K data</small>
                                @endif
                            </td>
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
                            <td colspan="10" style="text-align: center; padding: 24px; color: var(--muted);">
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
