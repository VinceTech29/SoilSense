import './bootstrap';

async function loadChartConstructor() {
    if (window.Chart) return window.Chart;
    const { default: Chart } = await import('chart.js/auto');
    window.Chart = Chart;
    return Chart;
}

// Shared UI utilities (non-blocking inline toast replacing window.alert)
window.SoilSenseUI = {
    toast(message, type = 'info') {
        const container = document.getElementById('globalToastContainer');
        if (!container) return;

        const toneMap = {
            success: { cls: 'success', icon: '<path d="m5 12 4 4L19 6"/>' },
            error: { cls: 'error', icon: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' },
            warning: { cls: 'demo', icon: '<path d="M10.3 3.9 1.8 18.2A2 2 0 0 0 3.5 21h17a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>' },
            info: { cls: 'evidence', icon: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>' },
        };
        const cfg = toneMap[type] || toneMap.info;

        const banner = document.createElement('div');
        banner.className = `notice ${cfg.cls} toast-banner`;
        banner.setAttribute('role', type === 'error' ? 'alert' : 'status');

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', '18');
        svg.setAttribute('height', '18');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.innerHTML = cfg.icon;

        const textWrap = document.createElement('div');
        const msgSpan = document.createElement('span');
        msgSpan.textContent = message;
        textWrap.appendChild(msgSpan);

        const dismissBtn = document.createElement('button');
        dismissBtn.type = 'button';
        dismissBtn.className = 'toast-dismiss';
        dismissBtn.setAttribute('aria-label', 'Dismiss notice');
        dismissBtn.textContent = 'Dismiss';
        dismissBtn.addEventListener('click', () => banner.remove());

        banner.appendChild(svg);
        banner.appendChild(textWrap);
        banner.appendChild(dismissBtn);

        container.innerHTML = '';
        container.appendChild(banner);
        banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        window.setTimeout(() => {
            if (banner.parentNode) banner.remove();
        }, 6500);
    },
};

document.addEventListener('DOMContentLoaded', async () => {
    // App Sidebar Mobile Drawer with focus management & Escape close
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('sidebar-open');
        overlay.hidden = false;
        if (mobileMenuBtn) mobileMenuBtn.setAttribute('aria-expanded', 'true');
        if (sidebarCloseBtn) sidebarCloseBtn.focus();
    }

    function closeSidebar({ restoreFocus = true } = {}) {
        if (!sidebar || !overlay) return;
        const wasOpen = sidebar.classList.contains('sidebar-open');
        sidebar.classList.remove('sidebar-open');
        overlay.hidden = true;
        if (mobileMenuBtn) {
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
            if (wasOpen && restoreFocus) mobileMenuBtn.focus();
        }
    }

    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openSidebar);
    if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', () => closeSidebar());
    if (overlay) overlay.addEventListener('click', () => closeSidebar());

    // Public Landing Mobile Navigation Drawer
    const publicMenuBtn = document.getElementById('publicMenuBtn');
    const publicNavDrawer = document.getElementById('publicNavDrawer');
    if (publicMenuBtn && publicNavDrawer) {
        const closePublicMenu = (restore = false) => {
            publicNavDrawer.hidden = true;
            publicMenuBtn.setAttribute('aria-expanded', 'false');
            if (restore) publicMenuBtn.focus();
        };

        publicMenuBtn.addEventListener('click', () => {
            const willOpen = publicNavDrawer.hidden;
            publicNavDrawer.hidden = !willOpen;
            publicMenuBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        publicNavDrawer.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => closePublicMenu(false));
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !publicNavDrawer.hidden) {
                closePublicMenu(true);
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('sidebar-open')) {
            closeSidebar();
        }
    });

    // Dashboard Real Soil pH Trend Chart (Chart.js, lazy-loaded only on chart pages)
    const dashboardPhCanvas = document.getElementById('dashboardPhChart');
    const trendRangeSelect = document.getElementById('trendRangeSelect');
    const trendDeltaLabel = document.getElementById('dashboardTrendDelta');
    if (dashboardPhCanvas && dashboardPhCanvas.dataset.series) {
        try {
            const Chart = await loadChartConstructor();
            const allSeries = JSON.parse(dashboardPhCanvas.dataset.series);
            let phChartInstance = null;

            const renderDashboardChart = (rangeKey) => {
                const subset = allSeries[rangeKey] || allSeries['14'] || { labels: [], values: [], delta_text: '' };
                if (trendDeltaLabel) {
                    trendDeltaLabel.textContent = subset.delta_text || 'Recorded sample history';
                }
                if (!Array.isArray(subset.labels) || subset.labels.length === 0) return;

                if (phChartInstance) {
                    phChartInstance.destroy();
                }

                phChartInstance = new Chart(dashboardPhCanvas, {
                    type: 'line',
                    data: {
                        labels: subset.labels,
                        datasets: [
                            {
                                label: 'Measured Soil pH',
                                data: subset.values,
                                borderColor: '#166534',
                                backgroundColor: 'rgba(22, 163, 74, 0.14)',
                                fill: true,
                                tension: 0.32,
                                spanGaps: true,
                                pointRadius: 4,
                                pointBackgroundColor: '#ffffff',
                                pointBorderColor: '#166534',
                                pointBorderWidth: 2.5,
                                borderWidth: 2.5,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => ctx.parsed.y !== null ? `Soil pH: ${ctx.parsed.y.toFixed(2)}` : 'Soil pH: N/A',
                                },
                            },
                        },
                        scales: {
                            y: {
                                suggestedMin: 4.5,
                                suggestedMax: 8.0,
                                ticks: {
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                grid: { color: '#eef2f6' },
                            },
                            x: {
                                ticks: {
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                    maxRotation: 0,
                                },
                                grid: { display: false },
                            },
                        },
                    },
                });
            };

            renderDashboardChart(trendRangeSelect ? trendRangeSelect.value : '14');
            if (trendRangeSelect) {
                trendRangeSelect.addEventListener('change', (e) => {
                    renderDashboardChart(e.target.value);
                });
            }
        } catch (err) {
            console.error('Failed to initialize dashboard pH chart:', err);
        }
    }

    // Initialize Chart.js 4-Sensor Trend Lines on Sample History page (lazy-loaded)
    // Left Y-axis: Soil pH & EC (mS/cm); Right Y-axis: Moisture (%) & Temperature (°C)
    const sensorCanvas = document.getElementById('sensorTrendChart');
    if (sensorCanvas && sensorCanvas.dataset.trend) {
        try {
            const trend = JSON.parse(sensorCanvas.dataset.trend);
            if (Array.isArray(trend.labels) && trend.labels.length > 0 && trend.has_data) {
                const Chart = await loadChartConstructor();
                const hasEc = Array.isArray(trend.ec) && trend.ec.some(v => v !== null && v !== undefined);
                const datasets = [
                    {
                        label: 'Soil pH',
                        data: trend.ph,
                        yAxisID: 'yLeft',
                        borderColor: '#166534',
                        backgroundColor: 'rgba(22, 101, 52, 0.12)',
                        tension: 0.3,
                        spanGaps: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                    },
                    {
                        label: 'Moisture (%)',
                        data: trend.moisture,
                        yAxisID: 'yRight',
                        borderColor: '#1d4ed8',
                        backgroundColor: 'rgba(29, 78, 216, 0.12)',
                        tension: 0.3,
                        spanGaps: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                    },
                    {
                        label: 'Temperature (°C)',
                        data: trend.temperature,
                        yAxisID: 'yRight',
                        borderColor: '#b45309',
                        backgroundColor: 'rgba(180, 83, 9, 0.12)',
                        tension: 0.3,
                        spanGaps: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                    },
                ];

                if (hasEc) {
                    datasets.splice(1, 0, {
                        label: 'EC (mS/cm)',
                        data: trend.ec,
                        yAxisID: 'yLeft',
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124, 58, 237, 0.12)',
                        tension: 0.3,
                        spanGaps: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                    });
                }

                new Chart(sensorCanvas, {
                    type: 'line',
                    data: {
                        labels: trend.labels,
                        datasets: datasets,
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                align: 'end',
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 8,
                                    color: '#334155',
                                    font: { family: 'Inter, sans-serif', size: 12, weight: '600' },
                                },
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => {
                                        const val = ctx.parsed.y;
                                        if (val === null || Number.isNaN(val)) {
                                            return `${ctx.dataset.label}: N/A`;
                                        }
                                        const units = {
                                            'Soil pH': 'pH',
                                            'EC (mS/cm)': 'mS/cm',
                                            'Moisture (%)': '%',
                                            'Temperature (°C)': '°C',
                                        };
                                        const unit = units[ctx.dataset.label] || '';
                                        return unit ? `${ctx.dataset.label}: ${val} ${unit}` : `${ctx.dataset.label}: ${val}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            yLeft: {
                                type: 'linear',
                                position: 'left',
                                beginAtZero: true,
                                ticks: {
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                title: {
                                    display: true,
                                    text: hasEc ? 'pH / EC (mS/cm)' : 'Soil pH',
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                grid: {
                                    color: '#eef2f6',
                                },
                            },
                            yRight: {
                                type: 'linear',
                                position: 'right',
                                beginAtZero: true,
                                ticks: {
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                title: {
                                    display: true,
                                    text: 'Moisture (%) / Temperature (°C)',
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                            x: {
                                ticks: {
                                    color: '#526174',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                grid: {
                                    display: false,
                                },
                            },
                        },
                    },
                });
            }
        } catch (err) {
            console.error('Failed to initialize 4-sensor trend chart:', err);
        }
    }
});
