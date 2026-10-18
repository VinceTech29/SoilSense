@props(['name' => 'sprout', 'size' => 20, 'class' => ''])

<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $class }}">
    @switch($name)
        @case('logo')
            <path d="M12 21C7 18 5 13 7 5c7 0 11 3 11 8 0 4-2 7-6 8Z"/><path d="M9 15c2-3 4-5 8-7"/>
            @break
        @case('grid')
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            @break
        @case('flask')
            <path d="M9 3h6"/><path d="M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3"/><path d="M7.5 16h9"/>
            @break
        @case('sprout')
            <path d="M12 22V12"/><path d="M7 3c4 0 5 3 5 6-4 0-6-2-6-5"/><path d="M17 6c-4 0-5 3-5 6 4 0 6-2 6-5"/>
            @break
        @case('history')
            <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>
            @break
        @case('file')
            <path d="M6 2h9l4 4v16H6Z"/><path d="M14 2v5h5"/><path d="M9 13h6M9 17h6"/>
            @break
        @case('cpu')
            <rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 1v3m6-3v3M9 20v3m6-3v3M20 9h3m-3 6h3M1 9h3m-3 6h3"/><rect x="9" y="9" width="6" height="6"/>
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>
            @break
        @case('wifi')
            <path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="20" r="1"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>
            @break
        @case('droplet')
            <path d="M12 2S5 10 5 15a7 7 0 0 0 14 0c0-5-7-13-7-13Z"/>
            @break
        @case('temp')
            <path d="M14 14.8V5a4 4 0 0 0-8 0v9.8a6 6 0 1 0 8 0Z"/><path d="M10 8v9"/>
            @break
        @case('bolt')
            <path d="m13 2-8 12h7l-1 8 8-12h-7Z"/>
            @break
        @case('chevron')
            <path d="m9 18 6-6-6-6"/>
            @break
        @case('search')
            <circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>
            @break
        @case('check')
            <path d="m5 12 4 4L19 6"/>
            @break
        @case('warning')
            <path d="M12 3 2 21h20Z"/><path d="M12 9v5m0 3h.01"/>
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16"/>
            @break
        @case('download')
            <path d="M12 3v12m-5-5 5 5 5-5"/><path d="M5 21h14"/>
            @break
        @case('map')
            <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2"/>
            @break
        @case('camera')
            <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>
            @break
        @case('lock')
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            @break
        @case('clipboard')
            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
            @break
        @case('sliders')
            <line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>
            @break
        @case('alert')
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            @break
        @case('arrow')
            <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            @break
    @endswitch
</svg>
