import { getCookieConsent } from '../composables/useCookieConsent';

declare global {
    interface Window {
        _paq?: unknown[][];
    }
}

/** matomo.js script tag appended - cannot be undone within the page load. */
let scriptLoaded = false;
/** Tracker config commands present in the _paq queue - reset on withdrawal. */
let commandsInitialized = false;

function getMatomoConfig(): { url: string; siteId: string } | null {
    const url = document.querySelector('meta[name="satflux-matomo-url"]')?.getAttribute('content')?.trim();
    const siteId = document.querySelector('meta[name="satflux-matomo-site-id"]')?.getAttribute('content')?.trim();
    if (!url || !siteId) {
        return null;
    }
    return { url, siteId };
}

/** Load Matomo only after analytics cookie consent (dynamic import - not on critical path). */
export function loadAnalyticsIfConsented(): void {
    if (getCookieConsent() !== 'all') {
        return;
    }

    const config = getMatomoConfig();
    if (!config) {
        return;
    }

    const trackerBase = config.url.replace(/\/$/, '') + '/';
    const _paq = (window._paq = window._paq || []);
    // Queue init is tracked separately from the script tag: a withdrawal
    // clears the queue, so a re-grant in the same session must rebuild the
    // tracker commands (the script cannot be appended twice).
    if (!commandsInitialized) {
        commandsInitialized = true;
        _paq.push(['setTrackerUrl', trackerBase + 'matomo.php']);
        _paq.push(['setSiteId', config.siteId]);
        _paq.push(['trackPageView']);
        _paq.push(['enableLinkTracking']);
    }

    if (!scriptLoaded) {
        scriptLoaded = true;
        const script = document.createElement('script');
        script.async = true;
        script.src = trackerBase + 'matomo.js';
        document.head.appendChild(script);
    }
}

/** Called when user accepts all cookies in the banner. */
export function onAnalyticsConsentGranted(): void {
    loadAnalyticsIfConsented();
}

/**
 * Called when consent drops below "all": drop commands still queued for the
 * tracker so nothing recorded before the withdrawal gets sent. The Matomo
 * script itself cannot be unloaded, but trackEvent stops pushing (it rechecks
 * consent) and page reloads won't load it again.
 */
export function onAnalyticsConsentWithdrawn(): void {
    commandsInitialized = false;
    if (window._paq) {
        window._paq.length = 0;
    }
}

/**
 * Matomo custom event, consent-gated by the same switch as page views: a
 * no-op until the tracker was loaded, and consent is rechecked on every call
 * so a withdrawal takes effect immediately. Category/action/name must never
 * carry user data - counters only (e.g. passkey adoption).
 */
export function trackEvent(category: string, action: string, name?: string): void {
    if (!commandsInitialized || !window._paq || getCookieConsent() !== 'all') {
        return;
    }
    window._paq.push(
        name === undefined
            ? ['trackEvent', category, action]
            : ['trackEvent', category, action, name],
    );
}
