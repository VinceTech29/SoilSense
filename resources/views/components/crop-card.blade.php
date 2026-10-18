@props(['crop'])

<article class="crop-card card">
    <div class="crop-image">
        <img src="{{ $crop['image'] ?? 'https://images.unsplash.com/photo-1592924357228-91a4daadcfea?auto=format&fit=crop&q=80&w=800' }}" alt="{{ $crop['name'] }} growing in a farm">
        <span class="sim-label">Simulated assessment</span>
    </div>
    <div class="crop-body">
        <div class="crop-heading">
            <div>
                <h3>{{ $crop['name'] }}</h3>
                <i>{{ $crop['scientific'] }}</i>
            </div>
            <x-badge :tone="$crop['tone'] ?? 'green'">{{ $crop['status'] }}</x-badge>
        </div>

        <div class="requirement">
            <span>Verified pH range</span>
            <b>{{ $crop['range'] }}</b>
        </div>

        <div class="rangebar">
            <span class="range-good"></span>
            <i style="left: 52%;"></i>
            <small style="left: 47%;">6.2</small>
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

        <a href="{{ route('crop.detail', ['name' => strtolower($crop['name'])]) }}" class="btn btn-secondary">
            <span>View crop details</span>
            <x-icon name="chevron" :size="15" />
        </a>
    </div>
</article>
