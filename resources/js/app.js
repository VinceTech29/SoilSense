import './bootstrap';

// Mobile Sidebar Drawer Navigation
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        if (sidebar && overlay) {
            sidebar.classList.add('sidebar-open');
            overlay.style.display = 'block';
        }
    }

    function closeSidebar() {
        if (sidebar && overlay) {
            sidebar.classList.remove('sidebar-open');
            overlay.style.display = 'none';
        }
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', openSidebar);
    }

    if (sidebarCloseBtn) {
        sidebarCloseBtn.addEventListener('click', closeSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
});
