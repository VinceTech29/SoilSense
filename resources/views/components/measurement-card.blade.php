@props(['item'])

<article class="card measure-card">
    <div class="measure-top">
        <span class="metric-icon tone-{{ $item['tone'] ?? 'green' }}">
            <x-icon :name="$item['icon']" />
        </span>
        <x-badge :tone="$item['tone'] ?? 'green'">{{ $item['badge'] }}</x-badge>
    </div>

    <span class="metric-label">{{ $item['name'] }}</span>

    <div class="metric-value {{ $item['value'] === 'Pending' ? 'pending' : '' }}">
        {{ $item['value'] }}
        @if(!empty($item['unit']))
            <small>{{ $item['unit'] }}</small>
        @endif
    </div>

    <span class="metric-meta">{{ $item['meta'] }}</span>

    @if($item['value'] !== 'Pending')
        <svg class="spark {{ ($item['tone'] ?? '') === 'amber' ? 'amber' : '' }}" viewBox="0 0 150 42" preserveAspectRatio="none">
            <path class="spark-fill" d="M0 35 C16 31,22 36,37 26 S60 15,73 22 S96 34,108 19 S130 12,150 8 V42 H0Z"/>
            <path d="M0 35 C16 31,22 36,37 26 S60 15,73 22 S96 34,108 19 S130 12,150 8"/>
        </svg>
    @else
        <div class="empty-spark">Waiting for a valid measurement</div>
    @endif

    <div class="measure-foot">
        <small>{{ $item['note'] }}</small>
        <a href="{{ route('analysis') }}">
            <span>Details</span>
            <x-icon name="chevron" :size="13" />
        </a>
    </div>
</article>
