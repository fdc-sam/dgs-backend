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
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const config = JSON.parse(canvas.dataset.chart);
        // Ink first, then the brand gold; area fills stay faint so lines read like the admin's line icons.
        const palette = ['#18181B', '#F0B800', '#1D5A8E', '#15803D', '#B3261E'];
        new Chart(canvas, {
            type: config.type ?? 'line',
            data: {
                labels: config.labels,
                datasets: config.series.map((s, i) => ({
                    label: s.label,
                    data: s.data,
                    borderColor: palette[i % palette.length],
                    backgroundColor: (config.type === 'bar' ? palette[i % palette.length] : palette[i % palette.length] + '12'),
                    fill: config.type !== 'bar' && i === 0,
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderRadius: config.type === 'bar' ? 6 : 0,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: config.series.length > 1, align: 'end', labels: { usePointStyle: true, boxWidth: 6, boxHeight: 6 } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: '#707078' } },
                    y: { beginAtZero: true, grid: { color: '#E6E6E9' }, border: { display: false }, ticks: { color: '#707078', callback: (v) => (config.money ? '₱' : '') + Number(v).toLocaleString() } },
                },
            },
        });
    });
}

/** Phones show x-admin.table rows as cards (.table-stack in app.css); each cell is labelled with its column header. */
function labelTableCells() {
    document.querySelectorAll('table.table-stack').forEach((table) => {
        const headers = Array.from(table.tHead?.rows[0]?.cells ?? []);
        Array.from(table.tBodies).flatMap((body) => Array.from(body.rows)).forEach((row) => {
            // A row with a spanning cell (Subtotal …, Day off) is a summary line: label and value side by side, no headers.
            if (Array.from(row.cells).some((cell) => cell.colSpan > 1)) {
                row.dataset.row ??= 'summary';
                return;
            }
            let column = 0;
            Array.from(row.cells).forEach((cell) => {
                const header = headers[column];
                if (header) {
                    cell.dataset.label ??= header.textContent.trim();
                    if (header.dataset.cell) cell.dataset.cell ??= header.dataset.cell;
                }
                column += cell.colSpan;
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', renderCharts);
document.addEventListener('DOMContentLoaded', labelTableCells);
Alpine.start();
// After Alpine, so x-model has already chosen each select's value when Select2 reads it.
startSelect2();
