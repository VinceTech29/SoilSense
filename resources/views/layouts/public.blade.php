<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SoilSense | IoT Soil Assessment &amp; Crop Recommendation System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="landing">
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <!-- Public Header -->
    <header class="public-nav">
        <a href="{{ route('landing') }}" class="logo">
            <span class="logo-mark"><x-icon name="logo" :size="23" /></span>
            <span>SoilSense</span>
        </a>
        <nav aria-label="Public navigation">
            <a href="{{ route('landing') }}">Home</a>
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="{{ route('crops') }}">Crop recommendations</a>
            <a href="#research">About the project</a>
        </nav>
        <div class="public-nav-actions">
            <a href="{{ route('dashboard') }}" class="btn btn-primary">Open Dashboard</a>
            <button type="button" class="btn btn-secondary public-menu-btn" id="publicMenuBtn" aria-label="Open menu" aria-expanded="false" aria-controls="publicNavDrawer">
                <x-icon name="menu" :size="18" />
                <span>Menu</span>
            </button>
        </div>
    </header>

    <div class="public-nav-drawer" id="publicNavDrawer" hidden>
        <nav aria-label="Mobile public navigation">
            <a href="{{ route('landing') }}">Home</a>
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="{{ route('crops') }}">Crop recommendations</a>
            <a href="#research">About the project</a>
            <a href="{{ route('dashboard') }}" class="btn btn-primary">Open Dashboard</a>
        </nav>
    </div>

    <main id="main-content" tabindex="-1">
        @yield('content')
    </main>

    <!-- Public Footer -->
    <footer>
        <div>
            <div class="logo logo-light">
                <span class="logo-mark"><x-icon name="logo" :size="23" /></span>
                <span>SoilSense</span>
            </div>
            <p>Understand Your Soil. Grow Smarter.</p>
        </div>
        <div>
            <b>Platform</b>
            <a href="{{ route('dashboard') }}">Dashboard</a>
            <a href="{{ route('crops') }}">Crop catalog</a>
            <a href="{{ route('device') }}">Device monitoring</a>
        </div>
        <div>
            <b>Project</b>
            <a href="#research">About SoilSense</a>
            <a href="#features">Research overview</a>
            <a href="mailto:contact@soilsense.edu.ph">Contact the project</a>
        </div>
        <div>
            <b>Important note</b>
            <p>Prototype assessments are preliminary and do not replace certified soil analysis.</p>
        </div>
        <small>&copy; 2026 SoilSense Academic Research Project · Philippines</small>
    </footer>

    @stack('scripts')
</body>
</html>
