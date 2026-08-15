import { listAccountEnvelopes } from "./deviceUnlock/accountPasskeyEnvelope";
import { isPasskeyPrfSupported } from "./deviceUnlock/passkeyPrf";

/**
 * Decides when to proactively offer creating an account passkey ("sign in
 * without your 24 words next time"). Offered only while the account has NO
 * server envelope, on capable platforms, and respecting a local snooze after
 * an explicit "not now".
 */

const SNOOZE_STORAGE_KEY = "satflux.passkey.offer_snooze.v1";
const SNOOZE_MS = 30 * 24 * 60 * 60 * 1000;

export function isPasskeyOfferSnoozed(now: number = Date.now()): boolean {
    try {
        const raw = localStorage.getItem(SNOOZE_STORAGE_KEY);
        if (!raw) {
            return false;
        }
        const snoozedAt = Number(raw);
        if (!Number.isFinite(snoozedAt)) {
            return false;
        }
        return now - snoozedAt < SNOOZE_MS;
    } catch {
        return false;
    }
}

export function snoozePasskeyOffer(now: number = Date.now()): void {
    try {
        localStorage.setItem(SNOOZE_STORAGE_KEY, String(now));
    } catch {
        // Storage unavailable - the offer simply reappears next time.
    }
}

/**
 * Post-sign-in check (requires an authenticated session for the envelope
 * list). Any failure means "do not offer" - the nudge must never get in the
 * way of a successful login.
 */
export async function shouldOfferPasskeyEnrollment(): Promise<boolean> {
    try {
        if (isPasskeyOfferSnoozed()) {
            return false;
        }
        if (!(await isPasskeyPrfSupported())) {
            return false;
        }
        return (await listAccountEnvelopes()).length === 0;
    } catch {
        return false;
    }
}
