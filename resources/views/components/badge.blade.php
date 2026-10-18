@props(['tone' => 'green', 'dot' => true])

<span {{ $attributes->merge(['class' => 'badge badge-' . $tone]) }}>
    @if($dot)
        <span class="badge-dot"></span>
    @endif
    {{ $slot }}
</span>
