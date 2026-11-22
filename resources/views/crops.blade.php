@extends('layouts.app', ['title' => 'Crop Recommendations'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Crop Recommendations</h1>
            <p>
                Explore candidate crops using documented FAO/DA-BAR requirements
                @if($latestSample)
                    and measurements from sample <b>{{ $latestSample->sample_code }}</b>.
                @else
                    against your soil sample measurements.
                @endif
            </p>
        </div>
        <button type="button" class="btn btn-secondary" id="compareCountBtn" disabled>Compare crops (0/3)</button>
    </div>

    <!-- Evidence Methodology Notice -->
    <div class="notice evidence">
        <x-icon name="flask" />
        <div>
            <b>How recommendations work</b>
            <span>Results compare available readings to crop-specific ranges. Suitability describes fit; evidence completeness describes how much is known. Neither guarantees harvest yield.</span>
        </div>
        <a href="#scoringMethodology">View scoring method</a>
    </div>

    <!-- Filter Bar -->
    <section class="filterbar card">
        <label class="search wide">
            <x-icon name="search" :size="18" />
            <input type="text" id="cropSearchInput" aria-label="Search crops" placeholder="Search tomato, pechay, eggplant...">
        </label>
        <select id="statusFilter" aria-label="Status filter">
            <option value="All">All statuses</option>
            <option value="Strong Match">Strong Match</option>
            <option value="Potential Match">Potential Match</option>
            <option value="Limited Match">Limited Match</option>
            <option value="Insufficient Data">Insufficient Data</option>
        </select>
        <select id="categoryFilter" aria-label="Category filter">
            <option value="All">All crop categories</option>
            <option value="Leafy vegetables">Leafy vegetables</option>
            <option value="Fruit vegetables">Fruit vegetables</option>
            <option value="Legumes & pods">Legumes &amp; pods</option>
            <option value="Root & tuber crops">Root &amp; tuber crops</option>
            <option value="Grain & cereal crops">Grain &amp; cereal crops</option>
        </select>
        <select id="sortFilter" aria-label="Sort order">
            <option value="completeness">Sort: Match score</option>
            <option value="name">Crop name (A–Z)</option>
        </select>
    </section>

    <!-- Catalog Count Head -->
    <div class="catalog-head">
        <span><b id="visibleCount">{{ count($crops) }} crops</b> match your filters</span>
        <small>
            @if($latestSample)
                Using {{ $usableParams ?? 0 }} valid parameter{{ ($usableParams ?? 0) === 1 ? '' : 's' }} · {{ $incompleteParams ?? 0 }} incomplete
            @else
                No sample evaluated yet · showing baseline profiles
            @endif
        </small>
    </div>

    <!-- Crop Grid -->
    <div class="crop-grid catalog" id="cropsGridContainer">
        @foreach($crops as $crop)
            <div class="crop-card-wrapper"
                 data-name="{{ strtolower($crop['name']) }}"
                 data-crop-label="{{ $crop['name'] }}"
                 data-scientific="{{ $crop['scientific'] }}"
                 data-status="{{ $crop['status'] }}"
                 data-category="{{ $crop['category'] ?? '' }}"
                 data-score="{{ $crop['score'] ?? 0 }}"
                 data-ph="{{ $crop['range'] }}"
                 data-temp="{{ $crop['min_temp'] !== null && $crop['max_temp'] !== null ? $crop['min_temp'] . '–' . $crop['max_temp'] . '°C' : 'N/A' }}"
                 data-moisture="{{ $crop['min_moisture'] !== null && $crop['max_moisture'] !== null ? $crop['min_moisture'] . '–' . $crop['max_moisture'] . '%' : 'N/A' }}"
                 data-ec="{{ $crop['max_ec'] !== null ? '<= ' . $crop['max_ec'] . ' mS/cm' : 'N/A' }}"
                 data-support="{{ $crop['support'] }}"
                 data-limit="{{ $crop['limit'] }}">
                <x-crop-card :crop="$crop" :selectable="true" headingLevel="h2" />
            </div>
        @endforeach
    </div>

    <!-- Empty Filter State -->
    <div class="empty-state-box" id="cropsEmptyState" hidden>
        <h3>No crops match your filters</h3>
        <p>Try clearing your search keyword or switching status and category filters back to all.</p>
        <button type="button" class="btn btn-secondary" id="resetCropFiltersBtn">Reset filters</button>
    </div>

    <!-- Compare Strip -->
    <section class="card compare-strip" id="compareStrip">
        <div>
            <x-icon name="sprout" :size="24" />
            <div>
                <b>Compare crop requirements side by side</b>
                <small>Check 2 or 3 crops above to inspect pH, temperature, moisture, EC limits, and suitability notes together.</small>
            </div>
        </div>
        <button type="button" class="btn btn-secondary" id="compareSelectedBtn" disabled>Compare selected crops</button>
    </section>

    <!-- Scoring Methodology Breakdown -->
    <section class="card methodology-card" id="scoringMethodology">
        <span class="section-kicker">Transparent rules</span>
        <h2>Suitability Scoring Methodology</h2>
        <p>Crop suitability uses weighted agronomic tolerances over valid sensor channels only. Uncalibrated moisture (`raw index`) and missing channels are excluded rather than guessed.</p>
        <div class="methodology-grid">
            <div class="methodology-item">
                <b>Soil pH (45% weight)</b>
                <small>Optimal band scores 100; tolerable range scores 75; outside tolerable range scores 15 and caps tier.</small>
            </div>
            <div class="methodology-item">
                <b>Salinity EC (25% weight)</b>
                <small>At or below max EC threshold scores 100; exceeding max EC scores 15 and flags salinity stress.</small>
            </div>
            <div class="methodology-item">
                <b>Soil Moisture (15% weight)</b>
                <small>Evaluated only when calibrated in % VWC. Raw ADC index readings are flagged as uncalibrated.</small>
            </div>
            <div class="methodology-item">
                <b>Temperature (15% weight)</b>
                <small>Compared against crop-specific ambient/soil thermal thresholds; requires at least 2 valid parameters.</small>
            </div>
        </div>
    </section>

    <!-- Side-by-Side Crop Comparison Modal -->
    <div class="compare-modal-backdrop" id="cropCompareModal" hidden role="dialog" aria-modal="true" aria-labelledby="cropCompareTitle">
        <div class="compare-modal">
            <div class="compare-modal-head">
                <div>
                    <span class="section-kicker">Side-by-side matrix</span>
                    <h2 id="cropCompareTitle">Crop Requirements Comparison</h2>
                </div>
                <button type="button" class="btn btn-ghost" id="closeCropCompareBtn" aria-label="Close comparison modal">Close</button>
            </div>
            <div class="table-scroll">
                <table id="cropCompareTable" style="white-space: normal;"></table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('cropSearchInput');
        const statusFilter = document.getElementById('statusFilter');
        const categoryFilter = document.getElementById('categoryFilter');
        const sortFilter = document.getElementById('sortFilter');
        const container = document.getElementById('cropsGridContainer');
        const countDisplay = document.getElementById('visibleCount');
        const emptyState = document.getElementById('cropsEmptyState');
        const resetBtn = document.getElementById('resetCropFiltersBtn');

        const compareCheckboxes = document.querySelectorAll('.crop-compare-checkbox');
        const compareCountBtn = document.getElementById('compareCountBtn');
        const compareSelectedBtn = document.getElementById('compareSelectedBtn');
        const compareModal = document.getElementById('cropCompareModal');
        const compareTable = document.getElementById('cropCompareTable');
        const closeCompareBtn = document.getElementById('closeCropCompareBtn');
        let selectedCrops = [];
        let lastCompareTrigger = null;

        function filterCrops() {
            const query = searchInput.value.toLowerCase().trim();
            const status = statusFilter.value;
            const category = categoryFilter.value;
            const cards = Array.from(container.getElementsByClassName('crop-card-wrapper'));

            let visibleCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const cardStatus = card.getAttribute('data-status');
                const cardCategory = card.getAttribute('data-category');

                const matchesQuery = !query || name.includes(query);
                const matchesStatus = (status === 'All') || (cardStatus === status);
                const matchesCategory = (category === 'All') || (cardCategory === category);

                if (matchesQuery && matchesStatus && matchesCategory) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            countDisplay.textContent = `${visibleCount} crop${visibleCount === 1 ? '' : 's'}`;
            emptyState.hidden = visibleCount > 0;
        }

        function sortCrops() {
            const sortBy = sortFilter.value;
            const cards = Array.from(container.getElementsByClassName('crop-card-wrapper'));
            cards.sort((a, b) => {
                if (sortBy === 'name') {
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                }
                return (parseFloat(b.getAttribute('data-score')) || 0) - (parseFloat(a.getAttribute('data-score')) || 0);
            });
            cards.forEach(card => container.appendChild(card));
        }

        function updateCompareButtons() {
            const count = selectedCrops.length;
            compareCountBtn.textContent = `Compare crops (${count}/3)`;
            compareCountBtn.disabled = count < 2;
            compareSelectedBtn.disabled = count < 2;
        }

        function openComparison(trigger) {
            if (selectedCrops.length < 2) return;
            lastCompareTrigger = trigger;

            const rows = [
                { label: 'Scientific name', key: 'scientific' },
                { label: 'Suitability tier', key: 'status' },
                { label: 'Match score', key: 'score', fmt: v => v && v !== '0' ? `${v}%` : 'Pending' },
                { label: 'Soil pH range', key: 'ph' },
                { label: 'Temperature range', key: 'temp' },
                { label: 'Moisture range', key: 'moisture' },
                { label: 'Max salinity (EC)', key: 'ec' },
                { label: 'Supporting factors', key: 'support' },
                { label: 'Limiting factors', key: 'limit' },
            ];

            const headerCells = selectedCrops.map(w => `<th>${w.dataset.cropLabel}</th>`).join('');
            const bodyRows = rows.map(r => {
                const cells = selectedCrops.map(w => {
                    const raw = w.dataset[r.key] || 'N/A';
                    return `<td>${r.fmt ? r.fmt(raw) : raw}</td>`;
                }).join('');
                return `<tr><th>${r.label}</th>${cells}</tr>`;
            }).join('');

            compareTable.innerHTML = `<thead><tr><th>Attribute</th>${headerCells}</tr></thead><tbody>${bodyRows}</tbody>`;
            compareModal.hidden = false;
            closeCompareBtn.focus();
        }

        function closeComparison() {
            compareModal.hidden = true;
            if (lastCompareTrigger) lastCompareTrigger.focus();
        }

        compareCheckboxes.forEach(cb => {
            cb.addEventListener('change', function () {
                const wrapper = this.closest('.crop-card-wrapper');
                if (this.checked) {
                    if (selectedCrops.length >= 3) {
                        this.checked = false;
                        if (window.SoilSenseUI) {
                            window.SoilSenseUI.toast('You can compare a maximum of 3 crops side by side.', 'warning');
                        }
                        return;
                    }
                    selectedCrops.push(wrapper);
                } else {
                    selectedCrops = selectedCrops.filter(item => item !== wrapper);
                }
                updateCompareButtons();
            });
        });

        compareCountBtn.addEventListener('click', () => openComparison(compareCountBtn));
        compareSelectedBtn.addEventListener('click', () => openComparison(compareSelectedBtn));
        closeCompareBtn.addEventListener('click', closeComparison);
        compareModal.addEventListener('click', (e) => {
            if (e.target === compareModal) closeComparison();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !compareModal.hidden) closeComparison();
        });

        searchInput.addEventListener('input', filterCrops);
        statusFilter.addEventListener('change', filterCrops);
        categoryFilter.addEventListener('change', filterCrops);
        sortFilter.addEventListener('change', () => {
            sortCrops();
            filterCrops();
        });
        resetBtn.addEventListener('click', () => {
            searchInput.value = '';
            statusFilter.value = 'All';
            categoryFilter.value = 'All';
            filterCrops();
        });
    });
</script>
@endpush
