@props(['crop', 'selectable' => false])

@php
    $minPh = isset($crop['min_ph']) && is_numeric($crop['min_ph']) ? (float) $crop['min_ph'] : null;
    $maxPh = isset($crop['max_ph']) && is_numeric($crop['max_ph']) ? (float) $crop['max_ph'] : null;
    $measuredPh = isset($crop['measured_ph']) && is_numeric($crop['measured_ph']) ? (float) $crop['measured_ph'] : null;

    $hasBand = $minPh !== null && $maxPh !== null && $maxPh > $minPh;
    $bandLeft = $hasBand ? max(0, min(100, ($minPh / 14) * 100)) : 0;
    $bandRight = $hasBand ? max(0, min(100, 100 - (($maxPh / 14) * 100))) : 100;
    $pinLeft = $measuredPh !== null ? max(3, min(97, ($measuredPh / 14) * 100)) : null;
@endphp

<article class="crop-card card">
    <div class="crop-image">
        <img src="{{ $crop['image'] ?? 'https://images.unsplash.com/photo-1592924357228-91a4daadcfea?auto=format&fit=crop&q=80&w=800' }}" alt="{{ $crop['name'] }} growing in a farm" loading="lazy" decoding="async">
        <span class="sim-label">{{ $measuredPh !== null ? 'Evaluated profile' : 'Reference profile' }}</span>
    </div>
    <div class="crop-body">
        <div class="crop-heading">
            <div>
                @if(($headingLevel ?? 'h3') === 'h2')
                    <h2>{{ $crop['name'] }}</h2>
                @else
                    <h3>{{ $crop['name'] }}</h3>
                @endif
                <i>{{ $crop['scientific'] }}</i>
            </div>
            <x-badge :tone="$crop['tone'] ?? 'green'">{{ $crop['status'] }}</x-badge>
        </div>

        <div class="requirement">
            <span>Verified pH range</span>
            <b>{{ $crop['range'] }}</b>
        </div>

        <div class="rangebar" aria-label="{{ $crop['name'] }} pH suitability bar">
            @if($hasBand)
                <span class="range-good" style="left: {{ round($bandLeft, 1) }}%; right: {{ round($bandRight, 1) }}%;"></span>
            @endif
            @if($pinLeft !== null)
                <i style="left: {{ round($pinLeft, 1) }}%;"></i>
                <small style="left: {{ round($pinLeft, 1) }}%;">{{ number_format($measuredPh, 1) }}</small>
            @else
                <small style="left: 50%; color: var(--muted);">0–14 scale</small>
            @endif
        </div>

        <div class="crop-points">
            <p>
                <x-icon name="check" :size="15" />
                <span><b>Supports:</b> {{ $crop['support'] }}</span>
            </p>
            <p>
                <x-icon name="warning" :size="15" />
                <span><b>Limits:</b> {{ $crop['limit'] }}</span>
            </p>
        </div>

        <div class="crop-card-actions">
            @if($selectable)
                <label class="crop-select-label">
                    <input type="checkbox" class="crop-compare-checkbox" value="{{ $crop['name'] }}" aria-label="Select {{ $crop['name'] }} to compare">
                    <span>Compare</span>
                </label>
            @endif
            <a href="{{ route('crop.detail', ['name' => strtolower(str_replace(' ', '-', $crop['name']))]) }}" class="btn btn-secondary">
                <span>View crop details</span>
                <x-icon name="chevron" :size="15" />
            </a>
        </div>
    </div>
</article>
