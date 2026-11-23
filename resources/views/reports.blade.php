@extends('layouts.app', ['title' => 'Reports & Exports'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Reports &amp; Exports</h1>
            <p>Access sample assessment reports, historical trends, crop matrices, and UTF-8 CSV exports.</p>
        </div>
        <a href="{{ route('analysis') }}" class="btn btn-primary">
            <x-icon name="plus" :size="18" />
            <span>New sample assessment</span>
        </a>
    </div>

    <!-- Report Quick Workflows Grid -->
    <section aria-labelledby="reportTemplatesHeading">
        <h2 class="sr-only" id="reportTemplatesHeading">Report templates and shortcuts</h2>
        <div class="report-type-grid">
            <article class="card report-type">
                <span class="metric-icon tone-green"><x-icon name="file" /></span>
                <h3>Sample assessment</h3>
                <p>Complete measurement, fertilizer recommendation, Rain Guard 72h, and provenance summary.</p>
                <a href="{{ route('report') }}" class="btn btn-secondary">Open latest report</a>
            </article>

            <article class="card report-type">
                <span class="metric-icon tone-green"><x-icon name="history" /></span>
                <h3>Measurement trends</h3>
                <p>Track valid 4-sensor readings across dates, plot locations, and registered ESP32 devices.</p>
                <a href="{{ route('history') }}#sensorTrendSection" class="btn btn-secondary">View sensor trends</a>
            </article>

            <article class="card report-type">
                <span class="metric-icon tone-green"><x-icon name="grid" /></span>
                <h3>Sample comparison</h3>
                <p>Compare quality checks, telemetry readings, and candidate crop matches side by side.</p>
                <a href="{{ route('history') }}" class="btn btn-secondary">Compare in History</a>
            </article>

            <article class="card report-type">
                <span class="metric-icon tone-green"><x-icon name="sprout" /></span>
                <h3>Crop suitability</h3>
                <p>Summarize crop-specific match percentages, limiting factors, and evidence completeness.</p>
                <a href="{{ route('crops') }}" class="btn btn-secondary">Browse crop matrix</a>
            </article>
        </div>
    </section>

    <!-- Recent Generated Reports Table -->
    <section class="card table-card">
        <div class="section-title table-title">
            <div>
                <span class="section-kicker">Generated documents</span>
                <h2>Recent reports</h2>
            </div>
            <div class="table-tools">
                <form method="GET" action="{{ route('reports') }}" class="row" id="reportsPeriodForm">
                    <select name="days" aria-label="Filter reports by time period" onchange="this.form.submit()">
                        <option value="0" {{ ($selectedDays ?? 0) === 0 ? 'selected' : '' }}>All time</option>
                        <option value="30" {{ ($selectedDays ?? 0) === 30 ? 'selected' : '' }}>Last 30 days</option>
                        <option value="90" {{ ($selectedDays ?? 0) === 90 ? 'selected' : '' }}>Last 90 days</option>
                    </select>
                </form>
                <a href="{{ route('settings.export.samples') }}" class="btn btn-secondary">
                    <x-icon name="download" :size="16" />
                    <span>Export CSV</span>
                </a>
            </div>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Type</th>
                        <th>Created</th>
                        <th>Data source</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($samples as $sample)
                        <tr>
                            <td>
                                <b>{{ $sample->sample_code }} Assessment</b>
                                <small>{{ $sample->name }} · {{ $sample->location ?? 'Unspecified' }}</small>
                            </td>
                            <td>Sample report</td>
                            <td>{{ $sample->collected_at->format('M d, Y') }}</td>
                            <td>{{ ucfirst($sample->source_type) }}</td>
                            <td>
                                <x-badge :tone="$sample->assessment_status === 'reviewed' ? 'green' : 'blue'">
                                    {{ ucfirst($sample->assessment_status) }}
                                </x-badge>
                            </td>
                            <td>
                                <a href="{{ route('report', ['id' => $sample->sample_code]) }}" class="btn btn-ghost btn-compact">
                                    Preview &amp; Print
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 24px; color: var(--muted);">
                                No soil sample reports match the selected time window.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- Export Info Notice -->
    <div class="notice evidence" style="margin-top: 24px;">
        <x-icon name="file" />
        <div>
            <b>CSV exports &amp; printable reports</b>
            <span>Export CSV downloads live sample telemetry, fertilizer recommendations, and Rain Guard advisories. Use Preview &amp; Print on any sample report for clean print-formatted output.</span>
        </div>
    </div>
@endsection
