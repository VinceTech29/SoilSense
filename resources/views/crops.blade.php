@extends('layouts.app', ['title' => 'Crop Recommendations'])

@section('content')
    <div class="page-title">
        <div>
            <h1>Crop Recommendations</h1>
            <p>Explore candidate crops using documented requirements and the valid measurements in sample SS-2025-018.</p>
        </div>
        <button type="button" class="btn btn-secondary" id="compareCountBtn" disabled>Compare crops (0)</button>
    </div>

    <!-- Evidence Methodology Notice -->
    <div class="notice evidence">
        <x-icon name="flask" />
        <div>
            <b>How recommendations work</b>
            <span>Results compare available readings to crop-specific ranges. Suitability describes fit; evidence completeness describes how much is known. Neither is a probability of successful growth.</span>
        </div>
        <a href="#compareStrip">View scoring method</a>
    </div>

    <!-- Filter Bar -->
    <section class="filterbar card">
        <label class="search wide">
            <x-icon name="search" :size="18" />
            <input type="text" id="cropSearchInput" placeholder="Search tomato, pechay, eggplant…">
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
            <option value="Legumes & pods">Legumes & pods</option>
            <option value="Root & tuber crops">Root & tuber crops</option>
            <option value="Grain & cereal crops">Grain & cereal crops</option>
        </select>
        <select id="sortFilter" aria-label="Sort order">
            <option value="completeness">Sort: Evidence completeness</option>
            <option value="name">Crop name (A–Z)</option>
        </select>
    </section>

    <!-- Catalog Count Head -->
    <div class="catalog-head">
        <span><b id="visibleCount">{{ count($crops) }} crops</b> match your filters</span>
        <small>Using 2 valid readings · 2 incomplete parameters</small>
    </div>

    <!-- Crop Grid -->
    <div class="crop-grid catalog" id="cropsGridContainer">
        @foreach($crops as $crop)
            <div class="crop-card-wrapper" data-name="{{ strtolower($crop['name']) }}" data-status="{{ $crop['status'] }}" data-category="{{ $crop['category'] ?? '' }}" data-score="{{ $crop['score'] ?? 0 }}">
                <x-crop-card :crop="$crop" />
            </div>
        @endforeach
    </div>

    <!-- Compare Strip -->
    <section class="card compare-strip" id="compareStrip">
        <div>
            <x-icon name="sprout" :size="24" />
            <div>
                <b>Compare crop requirements side by side</b>
                <small>Select up to three crops to inspect ranges, supporting evidence, limitations, and missing information.</small>
            </div>
        </div>
        <button type="button" class="btn btn-secondary" disabled>Compare selected crops</button>
    </section>
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
        }

        function sortCrops() {
            const sortBy = sortFilter.value;
            const cards = Array.from(container.getElementsByClassName('crop-card-wrapper'));
            cards.sort((a, b) => {
                if (sortBy === 'name') {
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                } else {
                    return (parseFloat(b.getAttribute('data-score')) || 0) - (parseFloat(a.getAttribute('data-score')) || 0);
                }
            });
            cards.forEach(card => container.appendChild(card));
        }

        searchInput.addEventListener('input', filterCrops);
        statusFilter.addEventListener('change', filterCrops);
        categoryFilter.addEventListener('change', filterCrops);
        sortFilter.addEventListener('change', () => {
            sortCrops();
            filterCrops();
        });
    });
</script>
@endpush
