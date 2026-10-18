@extends('layouts.app', ['title' => 'Reports & Exports'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Reports & Exports</h1>
            <p>Create presentation-ready summaries while preserving evidence and prototype disclosures.</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="alert('Report generator configured.')">
            <x-icon name="plus" :size="18" />
            <span>Create report</span>
        </button>
    </div>

    <!-- Report Type Templates Grid -->
    <div class="report-type-grid">
        <article class="card report-type">
            <span class="metric-icon tone-green"><x-icon name="file" /></span>
            <h3>Sample assessment</h3>
            <p>Complete measurement, interpretation, and provenance summary for an individual sample.</p>
            <button type="button" class="btn btn-secondary">Configure report</button>
        </article>

        <article class="card report-type">
            <span class="metric-icon tone-green"><x-icon name="history" /></span>
            <h3>Measurement trends</h3>
            <p>Track valid readings across dates, plot locations, and registered ESP32 devices.</p>
            <button type="button" class="btn btn-secondary">Configure report</button>
        </article>

        <article class="card report-type">
            <span class="metric-icon tone-green"><x-icon name="grid" /></span>
            <h3>Sample comparison</h3>
            <p>Compare quality checks, missing laboratory tests, and candidate crop differences.</p>
            <button type="button" class="btn btn-secondary">Configure report</button>
        </article>

        <article class="card report-type">
            <span class="metric-icon tone-green"><x-icon name="sprout" /></span>
            <h3>Crop suitability</h3>
            <p>Summarize crop-specific match percentages, limiting factors, and evidence completeness.</p>
            <button type="button" class="btn btn-secondary">Configure report</button>
        </article>
    </div>

    <!-- Recent Generated Reports Table -->
    <section class="card table-card">
        <div class="section-title table-title">
            <div>
                <span class="section-kicker">Generated documents</span>
                <h2>Recent reports</h2>
            </div>
            <div class="table-tools">
                <select aria-label="Time period">
                    <option>Last 30 days</option>
                    <option>Last 90 days</option>
                </select>
                <button type="button" class="btn btn-secondary">
                    <x-icon name="download" :size="16" />
                    <span>Export list</span>
                </button>
            </div>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Type</th>
                        <th>Created</th>
                        <th>Data period</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($samples as $sample)
                        <tr>
                            <td>
                                <b>{{ $sample->sample_code }} Assessment</b>
                                <br><small style="color: var(--text-muted);">{{ $sample->name }} · {{ $sample->location }}</small>
                            </td>
                            <td>Sample report</td>
                            <td>{{ $sample->collected_at->format('M d, Y') }}</td>
                            <td>Single sample</td>
                            <td>
                                <x-badge :tone="$sample->assessment_status === 'reviewed' ? 'green' : 'blue'">
                                    {{ ucfirst($sample->assessment_status) }}
                                </x-badge>
                            </td>
                            <td>
                                <a href="{{ route('report', ['id' => $sample->sample_code]) }}" class="btn btn-ghost" style="text-decoration: none;">
                                    Preview & Print
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 24px; color: var(--text-muted);">
                                No soil sample reports generated yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- Prototype Disclaimer -->
    <div class="notice evidence" style="margin-top: 24px;">
        <x-icon name="file" />
        <div>
            <b>Export interaction is a prototype</b>
            <span>PDF and CSV controls demonstrate the intended research workflow; generated files are rendered for presentation in this interface.</span>
        </div>
    </div>
@endsection
