<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} | SoilSense IoT Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet"></noscript>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        @php
            $navDeviceStatus = $sidebarDevice?->status ?? 'offline';
            $navDeviceOnline = $navDeviceStatus === 'online';
            $navDeviceBadgeTone = $navDeviceOnline ? 'green' : ($navDeviceStatus === 'error' ? 'red' : 'gray');
            $navLastSeen = $sidebarDevice && $sidebarDevice->last_seen_at
                ? 'Last sync: ' . $sidebarDevice->last_seen_at->diffForHumans()
                : 'Awaiting telemetry';
        @endphp

        <!-- Sidebar -->
        <aside class="sidebar" id="appSidebar" aria-label="Application sidebar">
            <div class="side-head">
                <a href="{{ route('landing') }}" class="logo logo-light">
                    <span class="logo-mark"><x-icon name="logo" :size="23" /></span>
                    <span>SoilSense</span>
                </a>
                <button type="button" class="icon-btn side-close" id="sidebarCloseBtn" aria-label="Close navigation">&times;</button>
            </div>

            <div class="workspace">
                <span class="workspace-icon" aria-hidden="true">UR</span>
                <span>
                    <b>Research Workspace</b>
                    <small>University prototype</small>
                </span>
            </div>

            <nav class="side-nav" aria-label="Primary navigation">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                    <x-icon name="grid" :size="19" />
                    <span>Overview</span>
                    @if(request()->routeIs('dashboard')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
                <a href="{{ route('analysis') }}" class="{{ request()->routeIs('analysis') ? 'active' : '' }}" @if(request()->routeIs('analysis')) aria-current="page" @endif>
                    <x-icon name="flask" :size="19" />
                    <span>Soil Analysis</span>
                    @if(request()->routeIs('analysis')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
                <a href="{{ route('crops') }}" class="{{ request()->routeIs('crops*') || request()->routeIs('crop.detail') ? 'active' : '' }}" @if(request()->routeIs('crops*') || request()->routeIs('crop.detail')) aria-current="page" @endif>
                    <x-icon name="sprout" :size="19" />
                    <span>Crop Recommendations</span>
                    @if(request()->routeIs('crops*') || request()->routeIs('crop.detail')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
                <a href="{{ route('history') }}" class="{{ request()->routeIs('history') ? 'active' : '' }}" @if(request()->routeIs('history')) aria-current="page" @endif>
                    <x-icon name="history" :size="19" />
                    <span>Sample History</span>
                    @if(request()->routeIs('history')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
                <a href="{{ route('reports') }}" class="{{ request()->routeIs('reports') || request()->routeIs('report') ? 'active' : '' }}" @if(request()->routeIs('reports') || request()->routeIs('report')) aria-current="page" @endif>
                    <x-icon name="file" :size="19" />
                    <span>Reports</span>
                    @if(request()->routeIs('reports') || request()->routeIs('report')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
                <a href="{{ route('device') }}" class="{{ request()->routeIs('device') ? 'active' : '' }}" @if(request()->routeIs('device')) aria-current="page" @endif>
                    <x-icon name="cpu" :size="19" />
                    <span>Device Monitoring</span>
                    @if(request()->routeIs('device')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
                <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings') ? 'active' : '' }}" @if(request()->routeIs('settings')) aria-current="page" @endif>
                    <x-icon name="settings" :size="19" />
                    <span>Settings</span>
                    @if(request()->routeIs('settings')) <span class="active-bar" aria-hidden="true"></span> @endif
                </a>
            </nav>

            <a href="{{ route('device') }}" class="side-device">
                <div class="row">
                    <span class="status-pulse {{ $navDeviceOnline ? '' : 'offline' }}" aria-hidden="true"></span>
                    <b>{{ $sidebarDevice?->device_uid ?? 'ESP32-01' }}</b>
                    <x-badge :tone="$navDeviceBadgeTone" :dot="false">{{ ucfirst($navDeviceStatus) }}</x-badge>
                </div>
                <small>{{ $navLastSeen }}</small>
                <div class="signal {{ $navDeviceOnline ? 'active' : 'dim' }}" aria-hidden="true">
                    <span></span><span></span><span></span><span></span>
                </div>
            </a>

            <a href="{{ route('settings') }}" class="side-profile">
                <span class="avatar" aria-hidden="true">{{ $currentUser?->initials ?? 'JD' }}</span>
                <span>
                    <b>{{ $currentUser?->name ?? 'Juan Dela Cruz' }}</b>
                    <small>{{ $currentUser?->role ?? 'Agricultural Researcher' }}</small>
                </span>
                <x-icon name="chevron" :size="16" />
            </a>
        </aside>

        <!-- Mobile Drawer Overlay -->
        <div class="overlay" id="sidebarOverlay" hidden></div>

        <!-- Main Shell -->
        <div class="main-shell">
            <header class="app-header">
                <div class="header-title">
                    <button type="button" class="icon-btn mobile-menu" id="mobileMenuBtn" aria-label="Open navigation" aria-expanded="false" aria-controls="appSidebar">
                        <x-icon name="menu" :size="20" />
                    </button>
                    <div>
                        <small>SoilSense / {{ $title ?? 'Overview' }}</small>
                        <strong>{{ $title ?? 'Overview' }}</strong>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="{{ route('device') }}" class="header-device-link" aria-label="Device status: {{ ucfirst($navDeviceStatus) }}">
                        <x-badge :tone="$navDeviceBadgeTone">Device {{ $navDeviceStatus }}</x-badge>
                    </a>
                    <a href="{{ route('analysis') }}" class="btn btn-primary header-new-test" aria-label="New Soil Test">
                        <x-icon name="plus" :size="18" />
                        <span class="header-cta-label">New Soil Test</span>
                    </a>
                </div>
            </header>

            <main class="app-main" id="main-content" tabindex="-1">
                <div id="globalToastContainer" class="toast-region" aria-live="polite" aria-atomic="true"></div>
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
