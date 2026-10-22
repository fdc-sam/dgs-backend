/**
 * Live admin pages: polls GET /live (App\Services\AdminLiveStatus) while the tab is visible.
 *  - Badges marked data-live-badge="unread|new_referrals|payments_to_verify" follow the latest counts
 *    (data-live-count shows the number; without it the badge is only shown or hidden, like the bell dot).
 *  - A page that watches a list (<x-admin.layout live="appointments">) notices when that list changed: it reloads
 *    by itself if nobody is using the page, otherwise it shows a "New updates · Refresh" alert.
 */
const POLL_MS = 20_000;
/** No clicks, keys or scrolling for this long counts as "nobody is using the page". */
const IDLE_MS = 30_000;

export default ({ url, watch = null, baseline = null }) => ({
    changed: false,
    lastActivity: Date.now(),
    baseTitle: document.title,

    init() {
        ['pointerdown', 'keydown', 'wheel', 'touchstart', 'input'].forEach((type) =>
            window.addEventListener(type, () => { this.lastActivity = Date.now(); }, { passive: true }));
        document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
        setInterval(() => this.poll(), POLL_MS);
    },

    async poll() {
        if (document.hidden) return;
        let data;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            // Signed out or the session ended: stay quiet; the next click goes to the login page anyway.
            if (!response.ok || !response.headers.get('content-type')?.includes('json')) return;
            data = await response.json();
        } catch {
            return;
        }

        this.updateBadges(data);
        const marker = watch ? data.markers?.[watch] : null;
        if (marker && baseline !== null && marker !== baseline) {
            this.changed = true;
            if (this.safeToReload()) this.refresh();
        }
    },

    updateBadges(data) {
        document.querySelectorAll('[data-live-badge]').forEach((badge) => {
            const count = Number(data[badge.dataset.liveBadge] ?? 0);
            badge.hidden = count <= 0;
            if ('liveCount' in badge.dataset) badge.textContent = count > 99 ? '99+' : String(count);
        });
        document.title = data.unread > 0 ? `(${data.unread}) ${this.baseTitle}` : this.baseTitle;
    },

    /** Never reload under someone's hands: not while they type, have a dialog open or used the page recently. */
    safeToReload() {
        if (Date.now() - this.lastActivity < IDLE_MS) return false;
        const active = document.activeElement;
        if (active?.matches('input, textarea, select, [contenteditable]')) return false;
        if (window.getSelection()?.toString()) return false;
        return ![...document.querySelectorAll('[role="dialog"]')].some((dialog) => getComputedStyle(dialog).display !== 'none');
    },

    refresh() {
        window.location.reload();
    },
});
