<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} — SoilSense IoT Platform</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    <div class="app-shell">
        <!-- Sidebar -->
        <aside class="sidebar" id="appSidebar">
            <div class="side-head">
                <a href="{{ route('landing') }}" class="logo logo-light">
                    <span class="logo-mark"><x-icon name="logo" :size="23" /></span>
                    <span>SoilSense</span>
                </a>
                <button type="button" class="icon-btn side-close" id="sidebarCloseBtn" aria-label="Close navigation">×</button>
            </div>

            <div class="workspace">
                <span class="workspace-icon">UR</span>
                <span>
                    <b>Research Workspace</b>
                    <small>University prototype</small>
                </span>
            </div>

            <nav class="side-nav">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <x-icon name="grid" :size="19" />
                    <span>Overview</span>
                    @if(request()->routeIs('dashboard')) <span class="active-bar"></span> @endif
                </a>
                <a href="{{ route('analysis') }}" class="{{ request()->routeIs('analysis') ? 'active' : '' }}">
                    <x-icon name="flask" :size="19" />
                    <span>Soil Analysis</span>
                    @if(request()->routeIs('analysis')) <span class="active-bar"></span> @endif
                </a>
                <a href="{{ route('crops') }}" class="{{ request()->routeIs('crops*') ? 'active' : '' }}">
                    <x-icon name="sprout" :size="19" />
                    <span>Crop Recommendations</span>
                    @if(request()->routeIs('crops*')) <span class="active-bar"></span> @endif
                </a>
                <a href="{{ route('history') }}" class="{{ request()->routeIs('history') ? 'active' : '' }}">
                    <x-icon name="history" :size="19" />
                    <span>Sample History</span>
                    @if(request()->routeIs('history')) <span class="active-bar"></span> @endif
                </a>
                <a href="{{ route('reports') }}" class="{{ request()->routeIs('reports') ? 'active' : '' }}">
                    <x-icon name="file" :size="19" />
                    <span>Reports</span>
                    @if(request()->routeIs('reports')) <span class="active-bar"></span> @endif
                </a>
                <a href="{{ route('device') }}" class="{{ request()->routeIs('device') ? 'active' : '' }}">
                    <x-icon name="cpu" :size="19" />
                    <span>Device Monitoring</span>
                    @if(request()->routeIs('device')) <span class="active-bar"></span> @endif
                </a>
                <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings') ? 'active' : '' }}">
                    <x-icon name="settings" :size="19" />
                    <span>Settings</span>
                    @if(request()->routeIs('settings')) <span class="active-bar"></span> @endif
                </a>
            </nav>

            <div class="side-device">
                <div class="row">
                    <span class="status-pulse"></span>
                    <b>ESP32-01</b>
                    <x-badge tone="green">Online</x-badge>
                </div>
                <small>Last sync: 2 minutes ago</small>
                <div class="signal">
                    <span></span><span></span><span></span><span></span>
                </div>
            </div>

            <a href="{{ route('settings') }}" class="side-profile" style="text-decoration: none; color: inherit;">
                <span class="avatar">{{ $currentUser?->initials ?? 'JD' }}</span>
                <span>
                    <b>{{ $currentUser?->name ?? 'Juan Dela Cruz' }}</b>
                    <small>{{ $currentUser?->role ?? 'Agricultural Researcher' }}</small>
                </span>
                <x-icon name="chevron" :size="16" />
            </a>
        </aside>

        <!-- Mobile Drawer Overlay -->
        <div class="overlay" id="sidebarOverlay" style="display: none;"></div>

        <!-- Main Shell -->
        <div class="main-shell">
            <header class="app-header">
                <div class="header-title">
                    <button type="button" class="icon-btn mobile-menu" id="mobileMenuBtn" aria-label="Open navigation">
                        <x-icon name="menu" :size="20" />
                    </button>
                    <div>
                        <small>SoilSense / {{ $title ?? 'Overview' }}</small>
                        <strong>{{ $title ?? 'Overview' }}</strong>
                    </div>
                </div>
                <div class="header-actions">
                    <x-badge tone="green">Device online</x-badge>
                    <button type="button" class="icon-btn" aria-label="Notifications">
                        <x-icon name="bell" :size="19" />
                    </button>
                    <a href="{{ route('analysis') }}?advance=1" class="btn btn-primary" id="btnHeaderNewSoilTest">
                        <x-icon name="plus" :size="18" />
                        <span>New Soil Test</span>
                    </a>
                </div>
            </header>

            <main class="app-main">
                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
