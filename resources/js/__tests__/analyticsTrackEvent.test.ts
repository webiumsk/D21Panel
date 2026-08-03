import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
    getCookieConsent: vi.fn(),
}));

vi.mock("../composables/useCookieConsent", () => ({
    getCookieConsent: mocks.getCookieConsent,
}));

function addMatomoMeta(): void {
    for (const [name, content] of [
        ["satflux-matomo-url", "https://matomo.example"],
        ["satflux-matomo-site-id", "7"],
    ]) {
        const meta = document.createElement("meta");
        meta.setAttribute("name", name);
        meta.setAttribute("content", content);
        document.head.appendChild(meta);
    }
}

beforeEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
    document.head.innerHTML = "";
    delete window._paq;
});

describe("analytics trackEvent", () => {
    it("is a no-op before the consented tracker load", async () => {
        mocks.getCookieConsent.mockReturnValue(null);
        const { trackEvent } = await import("../services/analytics");

        trackEvent("auth", "passkey_login_success");

        expect(window._paq).toBeUndefined();
    });

    it("stays a no-op when consent was refused even with Matomo configured", async () => {
        addMatomoMeta();
        mocks.getCookieConsent.mockReturnValue("essential");
        const { loadAnalyticsIfConsented, trackEvent } = await import("../services/analytics");

        loadAnalyticsIfConsented();
        trackEvent("auth", "passkey_login_success");

        expect(window._paq).toBeUndefined();
    });

    it("pushes events once the tracker was loaded with consent", async () => {
        addMatomoMeta();
        mocks.getCookieConsent.mockReturnValue("all");
        const { loadAnalyticsIfConsented, trackEvent } = await import("../services/analytics");

        loadAnalyticsIfConsented();
        trackEvent("auth", "passkey_offer_shown", "register");
        trackEvent("auth", "seed_login_success");

        expect(window._paq).toContainEqual(["trackEvent", "auth", "passkey_offer_shown", "register"]);
        expect(window._paq).toContainEqual(["trackEvent", "auth", "seed_login_success"]);
    });
});
