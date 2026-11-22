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
        <div class="spark-note-bar">
            <span>Status</span>
            <b>{{ $item['badge'] }}</b>
        </div>
    @else
        <div class="empty-spark">Waiting for a valid measurement</div>
    @endif

    <div class="measure-foot">
        <small>{{ $item['note'] }}</small>
        <a href="{{ route('history') }}" aria-label="View {{ strtolower($item['name']) }} history">
            <span>History</span>
            <x-icon name="chevron" :size="13" />
        </a>
    </div>
</article>
