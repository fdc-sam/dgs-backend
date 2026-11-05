import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import Chart from 'chart.js/auto';
import { startSelect2 } from './select2';
import adminLive from './live';

window.Alpine = Alpine;
window.Chart = Chart;

Alpine.plugin(collapse);
Alpine.data('adminLive', adminLive);

/** Confirm-before-submit for destructive or state-changing forms: <form data-confirm="Cancel this booking?"> */
document.addEventListener('submit', (event) => {
    if (event.target.dataset?.submitting === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }
    const message = event.target.dataset?.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

/** Give admin form submissions consistent feedback and stop accidental double submits. */
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.dataset.noLoading !== undefined) return;
    const method = (form.querySelector('input[name="_method"]')?.value || form.method || 'get').toLowerCase();
    if (method === 'get') return;

    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }
    form.dataset.submitting = 'true';

    const buttons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
    const submitter = event.submitter instanceof HTMLElement
        ? event.submitter
        : buttons[0];
    if (!submitter || submitter instanceof HTMLInputElement) return;

    const isSave = /save|create|add|update|record|send|publish/i.test(submitter.innerText || '');
    submitter.setAttribute('aria-busy', 'true');
    submitter.innerHTML = `<span class="material-symbols-outlined animate-spin" aria-hidden="true">progress_activity</span><span>${isSave ? 'Saving…' : 'Processing…'}</span>`;
    buttons.filter((button) => button !== submitter).forEach((button) => { button.disabled = true; });
    // Let the browser capture the clicked button's name/value before disabling it.
    window.setTimeout(() => { submitter.disabled = true; }, 0);
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

/** On phones, x-admin.table rows show a compact summary and open additional record details on demand. */
function labelTableCells() {
    const compact = window.matchMedia('(width < 48rem)').matches;
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
                    cell.dataset.label ??= header.dataset.mobileLabel || header.textContent.trim();
                    cell.dataset.fullLabel ??= header.textContent.trim();
                    if (header.dataset.cell) cell.dataset.cell ??= header.dataset.cell;
                    if (header.dataset.mobilePrimary) cell.dataset.mobilePrimary = 'true';
                    if (header.dataset.mobileSummary) cell.dataset.mobileSummary = 'true';
                    if (header.dataset.mobileOrder !== undefined) cell.style.order = header.dataset.mobileOrder;
                }
                column += cell.colSpan;
            });

            // Keep the key, amount, status/date row visible; expand for a couple of extra fields and actions.
            if (!compact) return;
            const cells = Array.from(row.cells);
            const primary = cells.find((cell) => cell.dataset.mobilePrimary === 'true') ?? cells[0];
            const amount = cells.find((cell) => cell.dataset.cell === 'amount');
            const summary = cells.filter((cell) => cell.dataset.mobileSummary === 'true');
            const visibleSummary = summary.length ? summary : cells.filter((cell) => cell !== primary && cell !== amount && cell.dataset.cell !== 'actions').slice(0, 2);
            const visible = new Set([primary, amount, ...visibleSummary].filter(Boolean));
            const hidden = cells.filter((cell) => !visible.has(cell));
            const preview = hidden
                .filter((cell) => cell.dataset.cell !== 'actions')
                .sort((a, b) => Number(a.style.order || 0) - Number(b.style.order || 0))
                .slice(0, 2);
            preview.forEach((cell, index) => {
                cell.dataset.mobilePreview = 'true';
                cell.style.order = String(100 + index);
                const value = document.createElement('span');
                value.className = 'table-mobile-cell-value';
                while (cell.firstChild) value.append(cell.firstChild);
                cell.append(value);
            });
            const actions = hidden.filter((cell) => cell.dataset.cell === 'actions');
            actions.forEach((cell) => { cell.style.order = '998'; });
            const remaining = hidden.filter((cell) => !preview.includes(cell) && !actions.includes(cell));

            hidden.forEach((cell) => { cell.hidden = true; });
            row.dataset.mobileCompact = 'true';
            if (row.tabIndex < 0) {
                row.tabIndex = 0;
                row.dataset.mobileTabindex = 'true';
            }

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'table-mobile-details';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Show row details');
            toggle.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">expand_more</span>';
            const toggleHost = [...visibleSummary]
                .sort((a, b) => Number(a.style.order || 0) - Number(b.style.order || 0))
                .at(-1) ?? primary;
            toggleHost?.append(toggle);

            const fullRecordCell = document.createElement('td');
            fullRecordCell.className = 'table-mobile-full-record';
            fullRecordCell.hidden = true;
            fullRecordCell.style.order = '999';
            fullRecordCell.innerHTML = '<button type="button" class="table-mobile-full-record-button">Full record <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span></button>';
            row.append(fullRecordCell);

            const setExpanded = (expanded) => {
                row.dataset.mobileExpanded = String(expanded);
                [...preview, ...actions, fullRecordCell].forEach((cell) => { cell.hidden = !expanded; });
                toggle.setAttribute('aria-expanded', String(expanded));
                toggle.setAttribute('aria-label', expanded ? 'Hide row details' : 'Show row details');
                toggle.querySelector('.material-symbols-outlined').textContent = expanded ? 'expand_less' : 'expand_more';
            };
            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                setExpanded(row.dataset.mobileExpanded !== 'true');
            });
            fullRecordCell.querySelector('button').addEventListener('click', (event) => {
                event.stopPropagation();
                openMobileRecordSheet(row, primary);
            });

            // The whole row acts as a thumb-sized disclosure target; links and controls keep their own behavior.
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, select, textarea, label')) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                setExpanded(row.dataset.mobileExpanded !== 'true');
            }, true);
            row.addEventListener('keydown', (event) => {
                if (event.target !== row || !['Enter', ' '].includes(event.key)) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                setExpanded(row.dataset.mobileExpanded !== 'true');
            }, true);

            // Keep unused cells hidden even when a row has no preview fields.
            remaining.forEach((cell) => { cell.hidden = true; });
        });
    });
}

function openMobileRecordSheet(row, primary) {
    const dialog = document.getElementById('mobile-record-sheet');
    if (!dialog) return;
    const title = dialog.querySelector('[data-record-title]');
    const fields = dialog.querySelector('[data-record-fields]');
    const titleSource = primary?.cloneNode(true);
    titleSource?.querySelectorAll('button, .table-mobile-details').forEach((node) => node.remove());
    title.textContent = titleSource?.innerText.trim().split('\n')[0] || 'Record details';
    fields.replaceChildren();

    Array.from(row.cells).forEach((cell) => {
        if (cell.dataset.cell === 'actions' || cell.classList.contains('table-mobile-full-record')) return;
        const item = document.createElement('div');
        item.className = 'mobile-record-field';
        const label = document.createElement('dt');
        label.textContent = cell.dataset.fullLabel || cell.dataset.label || 'Details';
        const value = document.createElement('dd');
        const content = cell.cloneNode(true);
        content.hidden = false;
        content.querySelector('.table-mobile-details')?.remove();
        content.removeAttribute('data-mobile-primary');
        while (content.firstChild) value.append(content.firstChild);
        item.append(label, value);
        fields.append(item);
    });

    if (!dialog.open) dialog.showModal();
}

function restoreMobileTableRows() {
    document.querySelectorAll('table.table-stack tbody tr').forEach((row) => {
        row.querySelectorAll('td[hidden]').forEach((cell) => { cell.hidden = false; });
        row.querySelectorAll('td[data-mobile-preview]').forEach((cell) => {
            const value = cell.querySelector(':scope > .table-mobile-cell-value');
            if (value) {
                while (value.firstChild) cell.insertBefore(value.firstChild, value);
                value.remove();
            }
            delete cell.dataset.mobilePreview;
        });
        row.querySelectorAll('.table-mobile-details').forEach((button) => button.remove());
        row.querySelectorAll('.table-mobile-full-record').forEach((cell) => cell.remove());
        if (row.dataset.mobileTabindex === 'true') row.removeAttribute('tabindex');
        delete row.dataset.mobileTabindex;
        delete row.dataset.mobileCompact;
        delete row.dataset.mobileExpanded;
    });
}

document.addEventListener('DOMContentLoaded', renderCharts);
/** Mark tables that are wider than their card (.is-scrollable): app.css then pins their Actions column. */
function watchTableOverflow() {
    const update = (wrapper) => wrapper.classList.toggle('is-scrollable', wrapper.scrollWidth > wrapper.clientWidth + 1);
    const observer = new ResizeObserver((entries) => entries.forEach((entry) => update(entry.target)));
    document.querySelectorAll('.table-scroll').forEach((wrapper) => {
        update(wrapper);
        observer.observe(wrapper);
    });
}

document.addEventListener('DOMContentLoaded', labelTableCells);
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('mobile-record-sheet');
    if (!dialog) return;
    dialog.querySelector('[data-close-record-sheet]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
});
window.matchMedia('(width < 48rem)').addEventListener('change', (event) => {
    if (event.matches) labelTableCells();
    else restoreMobileTableRows();
});
document.addEventListener('DOMContentLoaded', watchTableOverflow);
Alpine.start();
// After Alpine, so x-model has already chosen each select's value when Select2 reads it.
startSelect2();
