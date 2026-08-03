import { getCookieConsent } from '../composables/useCookieConsent';

declare global {
    interface Window {
        _paq?: unknown[][];
    }
}

let analyticsLoaded = false;

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
    if (analyticsLoaded || getCookieConsent() !== 'all') {
        return;
    }

    const config = getMatomoConfig();
    if (!config) {
        return;
    }

    analyticsLoaded = true;
    const trackerBase = config.url.replace(/\/$/, '') + '/';
    const _paq = (window._paq = window._paq || []);
    _paq.push(['setTrackerUrl', trackerBase + 'matomo.php']);
    _paq.push(['setSiteId', config.siteId]);
    _paq.push(['trackPageView']);
    _paq.push(['enableLinkTracking']);

    const script = document.createElement('script');
    script.async = true;
    script.src = trackerBase + 'matomo.js';
    document.head.appendChild(script);
}

/** Called when user accepts all cookies in the banner. */
export function onAnalyticsConsentGranted(): void {
    loadAnalyticsIfConsented();
}

/**
 * Matomo custom event, consent-gated by the same switch as page views: a
 * no-op until the tracker was loaded. Category/action/name must never carry
 * user data - counters only (e.g. passkey adoption).
 */
export function trackEvent(category: string, action: string, name?: string): void {
    if (!analyticsLoaded || !window._paq) {
        return;
    }
    window._paq.push(
        name === undefined
            ? ['trackEvent', category, action]
            : ['trackEvent', category, action, name],
    );
}
