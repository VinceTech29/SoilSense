import './bootstrap';
import Chart from 'chart.js/auto';

window.Chart = Chart;

// Mobile Sidebar Drawer Navigation & N-P-K History Chart
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

    // Initialize Chart.js N-P-K Trend Lines on Sample History page
    const npkCanvas = document.getElementById('npkTrendChart');
    if (npkCanvas && npkCanvas.dataset.trend) {
        try {
            const trend = JSON.parse(npkCanvas.dataset.trend);
            if (Array.isArray(trend.labels) && trend.labels.length > 0 && trend.has_data) {
                new Chart(npkCanvas, {
                    type: 'line',
                    data: {
                        labels: trend.labels,
                        datasets: [
                            {
                                label: 'Nitrogen (N)',
                                data: trend.nitrogen,
                                borderColor: '#16a34a',
                                backgroundColor: 'rgba(22, 163, 74, 0.12)',
                                tension: 0.3,
                                spanGaps: true,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                borderWidth: 2.5,
                            },
                            {
                                label: 'Phosphorus (P)',
                                data: trend.phosphorus,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37, 99, 235, 0.12)',
                                tension: 0.3,
                                spanGaps: true,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                borderWidth: 2.5,
                            },
                            {
                                label: 'Potassium (K)',
                                data: trend.potassium,
                                borderColor: '#d97706',
                                backgroundColor: 'rgba(217, 119, 6, 0.12)',
                                tension: 0.3,
                                spanGaps: true,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                borderWidth: 2.5,
                            },
                        ],
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
                                    font: { family: 'Inter, sans-serif', size: 12, weight: '600' },
                                },
                            },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => {
                                        const val = ctx.parsed.y;
                                        return val === null || Number.isNaN(val)
                                            ? `${ctx.dataset.label}: N/A`
                                            : `${ctx.dataset.label}: ${val} mg/kg`;
                                    },
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Concentration (mg/kg)',
                                    color: '#64748b',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                grid: {
                                    color: '#eef2f6',
                                },
                            },
                            x: {
                                grid: {
                                    display: false,
                                },
                            },
                        },
                    },
                });
            }
        } catch (err) {
            console.error('Failed to initialize N-P-K trend chart:', err);
        }
    }
});
