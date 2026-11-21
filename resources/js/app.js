import './bootstrap';
import Chart from 'chart.js/auto';

window.Chart = Chart;

// Mobile Sidebar Drawer Navigation & 4-Sensor History Chart
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

    // Initialize Chart.js 4-Sensor Trend Lines on Sample History page
    // Left Y-axis: Soil pH & EC (mS/cm); Right Y-axis: Moisture (%) & Temperature (°C)
    const sensorCanvas = document.getElementById('sensorTrendChart');
    if (sensorCanvas && sensorCanvas.dataset.trend) {
        try {
            const trend = JSON.parse(sensorCanvas.dataset.trend);
            if (Array.isArray(trend.labels) && trend.labels.length > 0 && trend.has_data) {
                new Chart(sensorCanvas, {
                    type: 'line',
                    data: {
                        labels: trend.labels,
                        datasets: [
                            {
                                label: 'Soil pH',
                                data: trend.ph,
                                yAxisID: 'yLeft',
                                borderColor: '#16a34a',
                                backgroundColor: 'rgba(22, 163, 74, 0.12)',
                                tension: 0.3,
                                spanGaps: true,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                borderWidth: 2.5,
                            },
                            {
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
                            },
                            {
                                label: 'Moisture (%)',
                                data: trend.moisture,
                                yAxisID: 'yRight',
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37, 99, 235, 0.12)',
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
                                title: {
                                    display: true,
                                    text: 'pH / EC (mS/cm)',
                                    color: '#64748b',
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
                                title: {
                                    display: true,
                                    text: 'Moisture (%) / Temperature (°C)',
                                    color: '#64748b',
                                    font: { family: 'Inter, sans-serif', size: 11, weight: '600' },
                                },
                                grid: {
                                    drawOnChartArea: false,
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
            console.error('Failed to initialize 4-sensor trend chart:', err);
        }
    }
});
