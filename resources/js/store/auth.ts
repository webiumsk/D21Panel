import { asApiError } from "../utils/apiError";
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import api from '../services/api';
import { ensureCsrfCookie } from '../services/csrf';
import { useStoresStore } from './stores';
import {
    clearStoredGuestMnemonic,
    getStoredGuestMnemonic,
    guestRecoveryMessage,
    guestRecoveryPublicKeyHexFromMnemonic,
    hydrateAccountMnemonicSession,
    signGuestRecoveryMessage,
    storeGuestMnemonic,
} from '../services/guestRecovery';
import { isInvoicingLocalFirst } from '../evolu/flags';
import { ensureEvoluBoundToAccountSeed } from '../evolu/bootstrap';
import { readRelayOverride, writeRelayOverrideUrl } from '@/evolu/relayOverrideStorage';
import { normalizeEvoluRelayBaseUrl } from '@/evolu/config';

function isEmailNotVerifiedResponse(error: { response?: { data?: unknown } } | null): boolean {
    const data = error?.response?.data as { code?: unknown } | undefined;
    return data?.code === 'email_not_verified';
}

function scheduleChoralaSync(): void {
    void import('../services/chorala').then(({ syncChoralaIdentity }) => syncChoralaIdentity());
}

/** Staged 6-digit email code (never the hash or payload) - see EmailCodeChallengeService. */
export interface EmailChallengeSummary {
    purpose: string;
    email: string;
    expires_at: string;
    resend_available_at: string;
    attempts_left: number;
    sends_left?: number;
}

export interface User {
    id: number;
    email: string;
    is_guest?: boolean;
    allows_satflux_email_changes?: boolean;
    guest_recovery_enrolled?: boolean;
    guest_upgrade_email_only?: boolean;
    requires_recovery_migration?: boolean;
    can_use_password_login?: boolean;
    pending_email_challenge?: EmailChallengeSummary | null;
    email_verified_at?: string;
    role?: string;
    /** Set once the one-time Pro trial was activated - null/absent = trial still available. */
    trial_consumed_at?: string | null;
    name?: string;
    plan?: {
        code: string;
        name: string;
        max_stores: number | null;
        max_api_keys: number | null;
        max_ln_addresses: number | null;
        max_companies?: number | null;
        included_companies?: number | null;
        extra_company_slots?: number;
        companies_unlimited?: boolean;
        features: string[];
    };
    subscription?: {
        status: string;
        expires_at: string | null;
        grace_ends_at: string | null;
    };
    plan_features?: {
        advanced_stats: boolean;
        automatic_exports: boolean;
        offline_payment_methods: boolean;
        business_invoicing?: boolean;
    };
    evolu_relay_url?: string | null;
}

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null);
    const loading = ref(false);
    let autoRestoreInFlight = false;

    const isAuthenticated = computed(() => user.value !== null);

    const requiresRecoveryMigration = computed(
        () => user.value?.requires_recovery_migration === true,
    );

    /** Drop in-memory user + tenant store selection (same as logout’s local cleanup; no API call). */
    function clearLocalAuthAndTenantState() {
        user.value = null;
        const storesStore = useStoresStore();
        storesStore.stores = [];
        storesStore.currentStore = null;
    }

    function normalizeUserPayload(data: User): User {
        return { ...data };
    }

    async function syncAccountSeedAfterAuth(mnemonic: string): Promise<void> {
        storeGuestMnemonic(mnemonic);
        try {
            await ensureEvoluBoundToAccountSeed();
        } catch {
            // Evolu init is best-effort; invoicing layout may retry on first visit.
        }
    }

    /**
     * Cache the profile relay URL for the NEXT page load (P2 phase 1):
     * createEvolu picks transports at module init, before auth, so the
     * override storage is the only way the profile relay reaches the
     * worker socket. A device-level "sync off" choice is never overwritten.
     */
    function cacheRelayOverrideFromProfile(profileRelayUrl: string | null): void {
        if (!isInvoicingLocalFirst()) return;
        const override = readRelayOverride();
        if (override.kind === 'disabled') return;
        const normalized = normalizeEvoluRelayBaseUrl(profileRelayUrl ?? '');
        writeRelayOverrideUrl(normalized);
    }

    async function fetchUser() {
        try {
            await ensureCsrfCookie();
            hydrateAccountMnemonicSession();
            const response = await api.get('/user');
            const previousUserId = user.value?.id ?? null;
            user.value = normalizeUserPayload(response.data);
            if ((user.value?.id ?? null) !== previousUserId) {
                scheduleChoralaSync();
            }
            cacheRelayOverrideFromProfile(user.value?.evolu_relay_url ?? null);
            const mnemonic = getStoredGuestMnemonic();
            if (mnemonic && isInvoicingLocalFirst()) {
                void ensureEvoluBoundToAccountSeed();
            }
        } catch (rawError) {
            const error = asApiError(rawError);
            const status = error?.response?.status ?? error?.status;
            if (status === 403 && isEmailNotVerifiedResponse(error)) {
                // Authenticated but unverified: keep whatever user we have so
                // the "check your email" screen can render instead of the
                // login form (GET /user itself no longer 403s; belt and braces).
                return;
            }
            if (status === 401 || status === 403) {
                user.value = null;
                scheduleChoralaSync();
                await tryAutoRestoreGuestFromStoredSeed();
                return;
            }
        }
    }

    async function tryAutoRestoreGuestFromStoredSeed() {
        if (autoRestoreInFlight) return;
        const mnemonic = getStoredGuestMnemonic();
        if (!mnemonic) return;

        autoRestoreInFlight = true;
        try {
            await ensureCsrfCookie();
            const chRes = await api.post('/auth/guest/recovery/challenge');
            const { challenge_id, nonce } = chRes.data.data;
            const message = guestRecoveryMessage(challenge_id, nonce);
            const pk = guestRecoveryPublicKeyHexFromMnemonic(mnemonic);
            const signature = signGuestRecoveryMessage(mnemonic, message);
            await api.post('/auth/guest/recovery', {
                challenge_id,
                recovery_public_key: pk,
                signature,
            });
            await fetchUser();
            await syncAccountSeedAfterAuth(mnemonic);
            const storesStore = useStoresStore();
            await storesStore.fetchStores();
        } catch {
            // Keep user unauthenticated when auto-restore fails; manual restore remains available.
        } finally {
            autoRestoreInFlight = false;
        }
    }

    async function login(email: string, password: string, remember = false) {
        loading.value = true;
        try {
            // Ensure CSRF cookie is set before login
            await ensureCsrfCookie();

            const response = await api.post('/auth/login', {
                email,
                password,
                remember,
            });
            // Auth responses carry a raw user without the computed /user
            // payload fields (guest_recovery_enrolled, can_use_password_login,
            // plan_features, subscription) - load the canonical payload so the
            // UI renders correctly without a hard refresh. fetchUser also
            // schedules the Chorala identity sync on the id transition.
            void response;
            await fetchUser();
            if (getStoredGuestMnemonic() && isInvoicingLocalFirst()) {
                void ensureEvoluBoundToAccountSeed();
            }
            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function completeLegacyRecoveryMigration(payload: {
        recoveryPublicKeyHex: string;
        mnemonic: string;
    }) {
        loading.value = true;
        try {
            await enrollGuestRecoveryPublicKey(payload.recoveryPublicKeyHex);
            await syncAccountSeedAfterAuth(payload.mnemonic);
            await fetchUser();
        } finally {
            loading.value = false;
        }
    }

    async function register(
        email: string,
        password: string,
        password_confirmation: string,
        consents?: { privacy_consent: boolean; terms_accepted: boolean },
    ) {
        loading.value = true;
        try {
            // Ensure CSRF cookie is set before register
            await ensureCsrfCookie();

            const response = await api.post('/auth/register', {
                email,
                password,
                password_confirmation,
                privacy_consent: consents?.privacy_consent ?? false,
                terms_accepted: consents?.terms_accepted ?? false,
            });
            // Session is not created until email is verified; do not treat as logged in.
            clearLocalAuthAndTenantState();
            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function continueAsGuest(recoveryPublicKeyHex?: string) {
        loading.value = true;
        try {
            await ensureCsrfCookie();
            const response = await api.post('/auth/guest', {
                ...(recoveryPublicKeyHex
                    ? { recovery_public_key: recoveryPublicKeyHex }
                    : {}),
            });
            await fetchUser();
            return response.data;
        } finally {
            loading.value = false;
        }
    }

    /** Link recovery public key while already logged in as guest (e.g. from Profile). */
    async function enrollGuestRecoveryPublicKey(recoveryPublicKeyHex: string) {
        loading.value = true;
        try {
            await ensureCsrfCookie();
            const response = await api.post('/auth/guest', {
                recovery_public_key: recoveryPublicKeyHex,
            });
            if (response.data?.user) {
                // Same user id - fetchUser will not re-trigger the Chorala sync.
                await fetchUser();
                scheduleChoralaSync();
            }
            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function restoreGuestFromMnemonic(mnemonic: string) {
        loading.value = true;
        try {
            await ensureCsrfCookie();
            const chRes = await api.post('/auth/guest/recovery/challenge');
            const { challenge_id, nonce } = chRes.data.data;
            const message = guestRecoveryMessage(challenge_id, nonce);
            const pk = guestRecoveryPublicKeyHexFromMnemonic(mnemonic);
            const signature = signGuestRecoveryMessage(mnemonic, message);
            const response = await api.post('/auth/guest/recovery', {
                challenge_id,
                recovery_public_key: pk,
                signature,
            });
            await fetchUser();
            await syncAccountSeedAfterAuth(mnemonic);
            const storesStore = useStoresStore();
            await storesStore.fetchStores();
            return response.data;
        } finally {
            loading.value = false;
        }
    }

    async function logout() {
        try {
            await api.post('/auth/logout');
        } finally {
            clearLocalAuthAndTenantState();
            clearStoredGuestMnemonic();
            scheduleChoralaSync();
        }
    }

    return {
        user,
        loading,
        isAuthenticated,
        requiresRecoveryMigration,
        fetchUser,
        login,
        completeLegacyRecoveryMigration,
        register,
        continueAsGuest,
        enrollGuestRecoveryPublicKey,
        restoreGuestFromMnemonic,
        logout,
    };
});




