import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import Chart from 'chart.js/auto';
import { startSelect2 } from './select2';

window.Alpine = Alpine;
window.Chart = Chart;

Alpine.plugin(collapse);

/** Confirm-before-submit for destructive or state-changing forms: <form data-confirm="Cancel this booking?"> */
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

/** Line/bar chart helper used by dashboard & reports: <canvas data-chart='{"type":"line","labels":[],"series":[...]}'> */
function renderCharts() {
    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const config = JSON.parse(canvas.dataset.chart);
        const palette = ['#7A5900', '#F0B800', '#1D5A8E', '#1E6B3A', '#B3261E'];
        new Chart(canvas, {
            type: config.type ?? 'line',
            data: {
                labels: config.labels,
                datasets: config.series.map((s, i) => ({
                    label: s.label,
                    data: s.data,
                    borderColor: palette[i % palette.length],
                    backgroundColor: (config.type === 'bar' ? palette[i % palette.length] : palette[i % palette.length] + '22'),
                    fill: config.type !== 'bar',
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: 2,
                    borderRadius: config.type === 'bar' ? 6 : 0,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: config.series.length > 1, labels: { usePointStyle: true } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#D2C5B455' }, ticks: { callback: (v) => (config.money ? '₱' : '') + Number(v).toLocaleString() } },
                },
            },
        });
    });
}

document.addEventListener('DOMContentLoaded', renderCharts);
Alpine.start();
// After Alpine, so x-model has already chosen each select's value when Select2 reads it.
startSelect2();
