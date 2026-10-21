import $ from 'jquery';
import select2 from 'select2';
import 'select2/dist/css/select2.css';

/*
 | Every <select> in the admin becomes a Select2 dropdown (searchable once it has a few options).
 | Opt out with <select data-native>.
 |
 | Select2 hides the real <select> and keeps it as the source of truth, so forms submit unchanged. Two bridges keep
 | the rest of the page working:
 |  - Select2 → page: a pick fires a native `change` event, which Alpine's x-model / @change and plain
 |    addEventListener('change') handlers listen for (Select2 alone only fires a jQuery event).
 |  - page → Select2: when scripts replace the options or Alpine sets the value, the shown selection is refreshed.
 */
select2(window, $);
window.$ = window.jQuery = $;

const SEARCH_FROM = 8; // options needed before the search box appears
const shown = new WeakMap(); // select → value Select2 is currently showing

function valueOf(select) {
    return select.multiple ? Array.from(select.selectedOptions, (o) => o.value).join('\u0001') : select.value;
}

function refresh(select) {
    if (shown.get(select) !== valueOf(select)) {
        shown.set(select, valueOf(select));
        $(select).trigger('change.select2'); // redraw only; does not fire page handlers
    }
}

function enhance(select) {
    if (select.dataset.native !== undefined || select.classList.contains('select2-hidden-accessible')) {
        return;
    }

    // data-no-placeholder: the empty option ("All branches") is a normal choice, not a grey placeholder.
    const placeholder = select.dataset.noPlaceholder !== undefined ? undefined : select.querySelector('option[value=""]')?.textContent.trim();
    // Sizing and grid placement on the <select> (w-44, max-sm:w-full, max-sm:col-span-2) move to the box that replaces it.
    const layoutClasses = Array.from(select.classList).filter((c) => /^([\w-]+:)*(w|min-w|max-w|col-span)-/.test(c));
    const modal = select.closest('[role="dialog"]');

    $(select).select2({
        width: '100%',
        placeholder: placeholder || undefined,
        // Optional dropdowns with an empty choice ("All categories", "Any") get an × to go back to it.
        allowClear: Boolean(placeholder) && !select.required && !select.multiple,
        minimumResultsForSearch: select.options.length >= SEARCH_FROM ? 0 : Infinity,
        // Also when a disabled <fieldset> holds it (view-only pages), which the select's own `disabled` doesn't show.
        disabled: select.matches(':disabled'),
        // Inside a dialog the list must live in it, or the dialog's backdrop and focus handling get in the way.
        dropdownParent: modal ? $(modal) : $(document.body),
    });

    const container = select.nextElementSibling;
    if (layoutClasses.some((c) => /^([\w-]+:)*(w|min-w|max-w)-/.test(c))) {
        container.style.width = '';
    }
    container.classList.add(...layoutClasses);
    container.classList.toggle('select2-error', select.classList.contains('field-error'));
    // data-container-class: extra classes for the Select2 box, e.g. room for a leading icon.
    container.classList.add(...(select.dataset.containerClass ?? '').split(' ').filter(Boolean));
    shown.set(select, valueOf(select));

    $(select).on('select2:select select2:unselect select2:clear', () => {
        shown.set(select, valueOf(select));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // Scripts that rebuild the options (e.g. stylists for the chosen service).
    new MutationObserver(() => refresh(select)).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['selected', 'disabled'] });
}

function enhanceWithin(root) {
    if (root instanceof HTMLSelectElement) {
        enhance(root);
    } else {
        root.querySelectorAll?.('select').forEach(enhance);
    }
}

let pending = false;
/** Alpine sets values without events; re-check every enhanced select after anything that may have changed one. */
function refreshAllSoon() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => {
        pending = false;
        document.querySelectorAll('select.select2-hidden-accessible').forEach(refresh);
    });
}

export function startSelect2() {
    enhanceWithin(document);

    // Selects added later: Alpine x-for rows, x-if blocks, script-built forms. Wait a frame so Alpine has set values.
    new MutationObserver((mutations) => {
        const added = mutations.flatMap((m) => Array.from(m.addedNodes)).filter((n) => n.nodeType === 1);
        if (added.length) {
            requestAnimationFrame(() => added.forEach((node) => node.isConnected && enhanceWithin(node)));
        }
    }).observe(document.body, { childList: true, subtree: true });

    ['click', 'change', 'input', 'keyup'].forEach((type) => document.addEventListener(type, refreshAllSoon, true));
}
