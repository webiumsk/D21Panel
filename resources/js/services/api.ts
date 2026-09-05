import axios from 'axios';
import { classifyApiErrorForFlash, GLOBAL_API_ERROR_MESSAGE_KEYS, type GlobalApiErrorKind } from './apiError';
import type { EmailChallengeSummary } from '../store/auth';
import type { BlinkMigrationAlert, Store } from '../store/stores';
import type { BtcPayApp, StoreDashboardStats, StoreSettings, UpdateStoreSettingsPayload } from '../types/btcpay';

declare module 'axios' {
    export interface AxiosRequestConfig {
        /** Set true when the caller fully handles errors itself - suppresses the global error flash. */
        skipErrorFlash?: boolean;
    }
}

/** Standard Laravel API response wrapper: { data: ..., message?: ... } */
export interface ApiEnvelope<T> {
    data: T;
    message?: string;
}

/** Laravel paginator body: rows in data, pagination fields alongside. */
export interface PagedList<T> {
    data?: T[];
    total?: number;
    current_page?: number;
    last_page?: number;
}

const api = axios.create({
    baseURL: '/api',
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    },
    withCredentials: true,
});

// Helper: get CSRF token from cookie (shared for api and postWeb)
function getCsrfToken(): string | null {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; XSRF-TOKEN=`);
    if (parts.length === 2) {
        return (parts.pop()?.split(';').shift() || null);
    }
    return null;
}

/** Session-authenticated GET for file downloads (PDF, etc.) - not under /api. */
export async function getWebBlob(path: string): Promise<Blob> {
    const { data } = await axios.get(path, {
        baseURL: '',
        responseType: 'blob',
        withCredentials: true,
    });
    return data;
}

export function businessDocumentPdfPath(companyId: string, documentId: string): string {
    return `/invoicing/companies/${companyId}/documents/${documentId}/pdf`;
}

export function businessDocumentIsdocPath(companyId: string, documentId: string): string {
    return `/invoicing/companies/${companyId}/documents/${documentId}/isdoc`;
}

export function businessDocumentUblPath(companyId: string, documentId: string): string {
    return `/invoicing/companies/${companyId}/documents/${documentId}/ubl`;
}

// Post to a web route (no /api prefix) - for password reset etc., avoids Sanctum auth
export async function postWeb<T = unknown>(path: string, data: object): Promise<T> {
    const csrf = getCsrfToken();
    const { data: result } = await axios.request<T>({
        method: 'post',
        url: path,
        baseURL: '',
        data,
        withCredentials: true,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
        },
    });
    return result;
}

// Ensure CSRF token is sent with all requests
// Axios automatically reads XSRF-TOKEN cookie and sets X-XSRF-TOKEN header,
// but we need to ensure the cookie is available
api.interceptors.request.use(
    async (config) => {
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            config.headers['X-XSRF-TOKEN'] = decodeURIComponent(csrfToken);
        }
        // Let browser set Content-Type with boundary for FormData (file uploads)
        if (config.data instanceof FormData) {
            delete config.headers['Content-Type'];
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Global error fallback: network failures, 5xx and 429 surface as a flash
// message so silent catch blocks still give the user feedback. Callers that
// fully handle errors themselves opt out with { skipErrorFlash: true }.
// The flash store carries an i18n KEY (translated in FlashMessage.vue) so this
// module never imports i18n - i18n.ts imports api.ts and would cycle.
function notifyGlobalApiError(kind: GlobalApiErrorKind): void {
    void (async () => {
        try {
            const { useFlashStore } = await import('../store/flash');
            useFlashStore().errorKey(GLOBAL_API_ERROR_MESSAGE_KEYS[kind]);
        } catch {
            // Pinia not ready (boot-time failure) - nothing to show yet.
        }
    })();
}

// Response interceptor for error handling
api.interceptors.response.use(
    (response) => response,
    (error) => {
        // Don't redirect on 401 in interceptor - let router guard handle it
        // Redirecting here causes infinite loops when router guard calls fetchUser()
        // Router guard will handle authentication redirects properly
        const kind = classifyApiErrorForFlash(error);
        if (kind && !error?.config?.skipErrorFlash) {
            notifyGlobalApiError(kind);
        }
        return Promise.reject(error);
    }
);

// Locale management
export const setLocale = async (locale: string): Promise<void> => {
    try {
        await api.post('/locale', { locale });
    } catch (error) {
        console.error('Failed to set locale:', error);
        throw error;
    }
};

export interface CreateStorePayload {
    name: string;
    default_currency: string;
    timezone: string;
    /** Omit on first-step create; configure wallet in a follow-up step. */
    wallet_type?: 'blink' | 'blitz' | 'flash' | 'lnaddress' | 'aqua_boltz' | 'cashu' | null;
    preferred_exchange?: string;
    connection_string?: string;
    mint_url?: string;
    lightning_address?: string;
}

// Stores API - typed wrapper for /stores endpoints. New store-scoped calls
// belong here (typed, one place to change URLs), not inline in components.
export const storesApi = {
    async list(): Promise<Store[]> {
        const { data } = await api.get<ApiEnvelope<Store[]>>('/stores');
        return data.data ?? [];
    },
    async get(storeId: string): Promise<Store> {
        const { data } = await api.get<ApiEnvelope<Store>>(`/stores/${storeId}`);
        return data.data;
    },
    async create(payload: CreateStorePayload): Promise<Store> {
        const { data } = await api.post<ApiEnvelope<Store>>('/stores', payload);
        return data.data;
    },
    async delete(storeId: string): Promise<{ message?: string; btcpay_deleted?: boolean }> {
        const { data } = await api.delete<{ message?: string; btcpay_deleted?: boolean }>(`/stores/${storeId}`);
        return data;
    },
    async setWalletType(storeId: string, walletType: 'blink' | 'blitz' | 'flash' | 'lnaddress' | 'aqua_boltz' | 'cashu' | 'nwc'): Promise<Store> {
        const { data } = await api.patch<ApiEnvelope<Store>>(`/stores/${storeId}/wallet-type`, { wallet_type: walletType });
        return data.data;
    },
    async snoozeBlinkMigrationAlert(storeId: string): Promise<{ blink_migration_alert: BlinkMigrationAlert }> {
        const { data } = await api.post<ApiEnvelope<{ blink_migration_alert: BlinkMigrationAlert }>>(
            `/stores/${storeId}/blink-migration-alert/snooze`,
        );
        return data.data;
    },
    async dismissBlinkMigrationAlert(storeId: string): Promise<{ blink_migration_alert: BlinkMigrationAlert }> {
        const { data } = await api.post<ApiEnvelope<{ blink_migration_alert: BlinkMigrationAlert }>>(
            `/stores/${storeId}/blink-migration-alert/dismiss`,
        );
        return data.data;
    },
    async dashboard(storeId: string, params?: { source?: string; refresh?: boolean }): Promise<StoreDashboardStats> {
        const apiParams: Record<string, string | number> = {};
        if (params?.source) apiParams.source = params.source;
        if (params?.refresh) apiParams.refresh = 1;
        const { data } = await api.get<ApiEnvelope<StoreDashboardStats>>(`/stores/${storeId}/dashboard`, { params: apiParams });
        return data.data;
    },
    async apps(storeId: string): Promise<BtcPayApp[]> {
        const { data } = await api.get<ApiEnvelope<BtcPayApp[]>>(`/stores/${storeId}/apps`);
        return data.data ?? [];
    },
    settings: {
        async get(storeId: string): Promise<StoreSettings> {
            const { data } = await api.get<ApiEnvelope<StoreSettings>>(`/stores/${storeId}/settings`);
            return data.data;
        },
        async update(storeId: string, payload: UpdateStoreSettingsPayload): Promise<StoreSettings> {
            const { data } = await api.put<ApiEnvelope<StoreSettings>>(`/stores/${storeId}/settings`, payload);
            return data.data;
        },
    },
    /** Returns the uploaded logo URL, or null when the response carries none. */
    async uploadLogo(storeId: string, file: File): Promise<string | null> {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await api.post<{ data?: { logo_url?: string | null; logoUrl?: string; imageUrl?: string } } & { logo_url?: string | null; logoUrl?: string; imageUrl?: string }>(`/stores/${storeId}/logo`, formData);
        const payload = data?.data ?? data;
        return payload?.logo_url ?? payload?.logoUrl ?? payload?.imageUrl ?? null;
    },
    async deleteLogo(storeId: string): Promise<void> {
        await api.delete(`/stores/${storeId}/logo`);
    },
};

export interface WalletConnectionDetails {
    id: string;
    type: 'blink' | 'blitz' | 'flash' | 'lnaddress' | 'aqua_boltz' | 'cashu' | 'nwc' | 'aqua_descriptor';
    status: string;
    configuration_source?: string | null;
    brand?: 'aqua' | 'bull' | 'blitz' | 'flash' | 'coinos' | null;
    masked_secret?: string | null;
    submitted_at?: string | null;
    secret_updated_at?: string | null;
    submitted_by_user_id?: number | null;
    bot_failure_message?: string | null;
    /** Replacing a connected wallet needs an email-code grant (WalletChangeConfirmationGuard). */
    change_confirmation?: WalletChangeConfirmationState | null;
}

export interface WalletChangeConfirmationState {
    required: boolean;
    guest_upgrade_required: boolean;
    pending: EmailChallengeSummary | null;
    granted_until: string | null;
}

export interface WalletChangeRequestResult {
    required: boolean;
    challenge?: EmailChallengeSummary;
}

export interface WalletChangeGrant extends WalletSecretReveal {
    granted_until: string | null;
}

/** Support queue row: a wallet connection enriched with store/actor context. */
export interface SupportWalletConnection extends WalletConnectionDetails {
    store_name?: string | null;
    submitted_by?: string | null;
    bot_failed_at?: string | null;
}

/** Response of the owner/support secret reveal endpoints. */
export interface WalletSecretReveal {
    secret: string;
    type: string;
    masked_secret?: string | null;
}

/** Password or LNURL/Nostr confirmation for sensitive wallet actions. */
export interface SensitiveActionConfirmation {
    password?: string;
    confirm_via_lnurl?: boolean;
    confirm_via_nostr?: boolean;
}

export interface CashuSettings {
    mint_url?: string | null;
    lightning_address?: string | null;
    trusted_mint_urls?: string[] | string | null;
    enabled?: boolean;
    unit?: "sat" | "usd" | string | null;
    max_melt_fee_reserve_sats?: number | string | null;
    max_melt_fee_reserve_percent_of_minted?: number | string | null;
}

export interface SamRockOtp {
    otp: string | null;
    expires_at: string | null;
    setup_url: string | null;
}

export interface SamRockCompleteResult extends SamRockOtp {
    status: string | null;
    error_message: string | null;
    cashu_fallback_configured?: boolean;
}

// Wallet domain API - wallet connection, Cashu plugin settings, SamRock pairing.
// New wallet-scoped calls belong here, not inline in components.
export const walletApi = {
    connection: {
        async get(storeId: string): Promise<WalletConnectionDetails | null> {
            const { data } = await api.get<ApiEnvelope<WalletConnectionDetails | null>>(`/stores/${storeId}/wallet-connection`);
            return data.data;
        },
        async create(storeId: string, payload: { type: string; secret: string; fallback_lightning_address?: string }): Promise<void> {
            await api.post(`/stores/${storeId}/wallet-connection`, payload);
        },
        async reveal(storeId: string, confirmation: SensitiveActionConfirmation): Promise<WalletSecretReveal> {
            const { data } = await api.post<ApiEnvelope<WalletSecretReveal>>(`/stores/${storeId}/wallet-connection/reveal`, confirmation);
            return data.data;
        },
        /** Replacing a CONNECTED wallet: re-auth + email code, confirm reveals the secret and grants the write. */
        change: {
            async request(storeId: string, confirmation: SensitiveActionConfirmation): Promise<WalletChangeRequestResult> {
                const { data } = await api.post<ApiEnvelope<WalletChangeRequestResult>>(`/stores/${storeId}/wallet-connection/change/request`, confirmation);
                return data.data;
            },
            async confirm(storeId: string, code: string): Promise<WalletChangeGrant> {
                const { data } = await api.post<ApiEnvelope<WalletChangeGrant>>(`/stores/${storeId}/wallet-connection/change/confirm`, { code });
                return data.data;
            },
            async resend(storeId: string): Promise<EmailChallengeSummary | null> {
                const { data } = await api.post<ApiEnvelope<{ challenge: EmailChallengeSummary | null }>>(`/stores/${storeId}/wallet-connection/change/resend`);
                return data.data?.challenge ?? null;
            },
        },
        // Note: this endpoint responds without the { data } envelope
        async test(storeId: string, payload: { connection_string: string; crypto_code: string }): Promise<{ success?: boolean; message?: string; requires_manual_config?: boolean }> {
            const { data } = await api.post<{ success?: boolean; message?: string; requires_manual_config?: boolean }>(`/stores/${storeId}/wallet-connection/test`, payload);
            return data;
        },
        /** Server-side LUD-21 verify probe for unknown Lightning-address domains. */
        async lnaddressProbe(storeId: string, address: string): Promise<{ lud21: boolean; reason: string }> {
            const { data } = await api.post<ApiEnvelope<{ lud21: boolean; reason: string }>>(
                `/stores/${storeId}/wallet-connection/lnaddress-probe`,
                { address },
            );
            return data.data;
        },
    },
    cashu: {
        async getSettings(storeId: string): Promise<CashuSettings> {
            const { data } = await api.get<ApiEnvelope<CashuSettings | null>>(`/stores/${storeId}/cashu/settings`);
            return data.data ?? {};
        },
        async updateSettings(storeId: string, payload: CashuSettings): Promise<CashuSettings> {
            const { data } = await api.put<ApiEnvelope<CashuSettings | null>>(`/stores/${storeId}/cashu/settings`, payload);
            return data.data ?? {};
        },
        async confirmEdit(storeId: string, confirmation: SensitiveActionConfirmation): Promise<{ ok?: boolean }> {
            const { data } = await api.post<ApiEnvelope<{ ok?: boolean }>>(`/stores/${storeId}/cashu/confirm-edit`, confirmation);
            return data.data ?? {};
        },
    },
    samrock: {
        async createOtp(
            storeId: string,
            payload: { btc?: boolean; btcln?: boolean; lbtc?: boolean; expires_in_seconds?: number } = {},
        ): Promise<SamRockOtp> {
            const { data } = await api.post<ApiEnvelope<SamRockOtp>>(`/stores/${storeId}/samrock/otps`, payload);
            return data.data;
        },
        async otpStatus(storeId: string, otp: string): Promise<{ status?: string | null; error_message?: string | null }> {
            const { data } = await api.get<ApiEnvelope<{ status?: string | null; error_message?: string | null }>>(`/stores/${storeId}/samrock/otps/${encodeURIComponent(otp)}`);
            return data.data ?? {};
        },
        async deleteOtp(storeId: string, otp: string): Promise<void> {
            await api.delete(`/stores/${storeId}/samrock/otps/${encodeURIComponent(otp)}`);
        },
        async otpQr(storeId: string, otp: string, params?: { format?: string }): Promise<Blob> {
            const { data } = await api.get<Blob>(`/stores/${storeId}/samrock/otps/${encodeURIComponent(otp)}/qr`, { responseType: 'blob', params });
            return data;
        },
        async complete(storeId: string, payload: { otp: string; fallback_lightning_address: string }): Promise<SamRockCompleteResult> {
            const { data } = await api.post<ApiEnvelope<SamRockCompleteResult>>(`/stores/${storeId}/samrock/complete`, payload);
            return data.data;
        },
    },
    boltz: {
        async readiness(storeId: string, params?: { refresh?: boolean }): Promise<BoltzReadiness> {
            const { data } = await api.get<ApiEnvelope<BoltzReadiness>>(`/stores/${storeId}/boltz/readiness`, {
                params: params?.refresh ? { refresh: 1 } : undefined,
            });
            return data.data;
        },
    },
};

export interface StoreSettlement {
    id: string;
    btcpay_invoice_id: string;
    payment_method_id: string;
    payment_id: string;
    category: 'lightning_boltz' | 'lightning' | 'onchain' | 'other';
    destination: string | null;
    payment_status: string | null;
    paid_at: string | null;
    gross_sats: number;
    invoice_currency: string | null;
    invoice_amount: string | null;
    rate: string | null;
    settlement_asset: string | null;
    estimated_service_fee_sats: number | null;
    estimated_network_fee_sats: number | null;
    estimated_net_settlement_sats: number | null;
    estimate_basis: Record<string, unknown> | null;
    net_quality: 'unknown' | 'estimated' | 'derived' | 'reported' | 'final';
    boltz_swap_id: string | null;
    settlement_txid: string | null;
    flags: Record<string, unknown> | null;
    synced_at: string;
}

// Settlement ledger - synced from BTCPay invoice payments; Boltz net side is estimated.
export const settlementsApi = {
    async list(
        storeId: string,
        params?: { invoice_id?: string; per_page?: number; page?: number },
    ): Promise<PagedList<StoreSettlement>> {
        const { data } = await api.get<PagedList<StoreSettlement>>(`/stores/${storeId}/settlements`, { params });
        return data;
    },
    async sync(
        storeId: string,
        payload?: { invoice_id?: string; limit?: number },
    ): Promise<{ invoices?: number; rows?: number }> {
        const { data } = await api.post<ApiEnvelope<{ invoices?: number; rows?: number }>>(
            `/stores/${storeId}/settlements/sync`,
            payload ?? {},
        );
        return data.data ?? {};
    },
};

export type BoltzReadinessStatus = 'ready' | 'degraded' | 'stale' | 'unavailable' | 'misconfigured' | 'unsupported';

export interface BoltzReadiness {
    status: BoltzReadinessStatus;
    reasons: string[];
    wallet_type: string | null;
    plugin: { reachable: boolean | null; enabled: boolean | null; wallet_readonly: boolean | null };
    lightning_active: boolean | null;
    onchain_fallback: boolean | null;
    limits: { min: number; max: number; source: string; fetched_at: string; is_stale: boolean } | null;
    fees: { percentage: number; miner_fees: Record<string, number> } | null;
    checked_at: string;
}

// Support wallet API - support/admin flows over wallet connections.
export const supportWalletApi = {
    async reveal(connectionId: string, confirmation: SensitiveActionConfirmation): Promise<WalletSecretReveal> {
        const { data } = await api.post<ApiEnvelope<WalletSecretReveal>>(`/support/wallet-connections/${connectionId}/reveal`, confirmation);
        return data.data;
    },
    async btcpayStoreUrl(connectionId: string): Promise<{ url: string; store_id?: string | null }> {
        const { data } = await api.get<ApiEnvelope<{ url: string; store_id?: string | null }>>(`/support/wallet-connections/${connectionId}/btcpay-store-url`);
        return data.data;
    },
    async markConnected(connectionId: string): Promise<void> {
        await api.put(`/support/wallet-connections/${connectionId}/mark-connected`);
    },
};

export interface CompanySummary {
    trade_name?: string | null;
    legal_name?: string | null;
    has_bank_account?: boolean;
    bank_account_label?: string | null;
    default_currency?: string | null;
}

/** Sealed invite blob - opaque to the server (services/companyInviteSeal.ts). */
export interface SealedInviteBlob {
    v: 1;
    epkB64: string;
    ivB64: string;
    ctB64: string;
}

export interface InviteRecipientLookup {
    found: boolean;
    public_key: string | null;
    has_recovery: boolean;
    is_self?: boolean;
    already_member?: boolean;
}

export interface CompanyInviteSummary {
    id: string;
    role: string;
    mode: 'sealed' | 'link';
    invited_email: string | null;
    invitee_public_key: string | null;
    expires_at: string | null;
    created_at: string | null;
}

export interface CreateCompanyInvitePayload {
    role: 'accountant' | 'member';
    mode: 'sealed' | 'link';
    invited_email?: string | null;
    invitee_public_key?: string | null;
    sealed_secret?: SealedInviteBlob | null;
}

export interface CompanyMemberSummary {
    id: string;
    role: string;
    name: string | null;
    email: string | null;
    accepted_at: string | null;
}

export interface CompanyInvitePreview {
    company_id: string;
    company_name: string | null;
    role: string;
    mode: 'sealed' | 'link';
    invited_by: string | null;
    invitee_public_key: string | null;
    sealed_secret: SealedInviteBlob | null;
}

export interface ContactListMeta {
    letters?: string[];
    total?: number;
    last_page?: number;
}

export interface ContactListParams {
    q?: string;
    letter?: string;
    page?: number;
    per_page?: number;
}

/**
 * Invoicing domain API - companies core cluster (record, settings, branding,
 * contacts). Documents/expenses/stock/wise/efaktura endpoints migrate here in
 * follow-ups; new invoicing calls belong here, not inline in components.
 * Company records are dynamic form payloads - responses are kept as
 * Record<string, unknown> equivalents (InvoicingCompanyRecord lives in
 * evolu/companyMap and is imported type-only by callers that need it).
 */
export const invoicingApi = {
    companies: {
        async list<T = unknown>(): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>('/invoicing/companies');
            return data.data ?? [];
        },
        async get<T = unknown>(companyId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}`);
            return data.data;
        },
        async create<T = unknown>(payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>('/invoicing/companies', payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}`, payload);
            return data.data;
        },
        async delete(companyId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}`);
        },
        async summary(companyId: string): Promise<CompanySummary> {
            const { data } = await api.get<ApiEnvelope<CompanySummary | null>>(`/invoicing/companies/${companyId}/summary`);
            return data.data ?? {};
        },
        async updateStores(companyId: string, storeIds: string[]) {
            // Returns {data: company-with-stores} - callers refresh their
            // payload from it after re-linking.
            return api.patch(`/invoicing/companies/${companyId}/stores`, { store_ids: storeIds });
        },
        async resetData(companyId: string, payload: Record<string, unknown>): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/reset-data`, payload);
        },
        /** Server-mode "balík pre účtovníka" ZIP for a period. */
        async accountantExport(
            companyId: string,
            params: Record<string, string | number | boolean | string[]>,
            options?: { signal?: AbortSignal },
        ): Promise<Blob> {
            const { data } = await api.get<Blob>(`/invoicing/companies/${companyId}/accountant-export`, {
                params,
                responseType: 'blob',
                signal: options?.signal,
            });
            return data;
        },
        async updateAppSettings<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/app-settings`, payload);
            return data.data;
        },
        async getAutoIssueProfile<T = unknown>(companyId: string): Promise<T | null> {
            const { data } = await api.get<ApiEnvelope<T | null>>(`/invoicing/companies/${companyId}/auto-issue-profile`);
            return data.data ?? null;
        },
        async putAutoIssueProfile<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.put<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/auto-issue-profile`, payload);
            return data.data;
        },
        async deleteAutoIssueProfile(companyId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/auto-issue-profile`);
        },
        async updateEmailSettings<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/email-settings`, payload);
            return data.data;
        },
        async testSmtp(companyId: string, payload: { to: string }): Promise<{ message?: string }> {
            const { data } = await api.post<{ message?: string }>(`/invoicing/companies/${companyId}/email-settings/test-smtp`, payload);
            return data;
        },
        branding: {
            async uploadLogo<T = unknown>(companyId: string, file: File): Promise<T> {
                const fd = new FormData();
                fd.append('image', file);
                const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/branding/logo`, fd);
                return data.data;
            },
            async deleteLogo<T = unknown>(companyId: string): Promise<T> {
                const { data } = await api.delete<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/branding/logo`);
                return data.data;
            },
            async uploadSignatureStamp<T = unknown>(companyId: string, file: File): Promise<T> {
                const fd = new FormData();
                fd.append('image', file);
                const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/branding/signature-stamp`, fd);
                return data.data;
            },
            async deleteSignatureStamp<T = unknown>(companyId: string): Promise<T> {
                const { data } = await api.delete<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/branding/signature-stamp`);
                return data.data;
            },
        },
        /** Company sharing invites - owner-only (docs/COMPANY_SHARING.md, "C4"). */
        async lookupInviteRecipient(companyId: string, email: string): Promise<InviteRecipientLookup> {
            const { data } = await api.get<InviteRecipientLookup>(`/invoicing/companies/${companyId}/invite-recipient`, { params: { email } });
            return data;
        },
        async listInvites(companyId: string): Promise<CompanyInviteSummary[]> {
            const { data } = await api.get<ApiEnvelope<CompanyInviteSummary[]>>(`/invoicing/companies/${companyId}/invites`);
            return data.data ?? [];
        },
        async createInvite(companyId: string, payload: CreateCompanyInvitePayload): Promise<{ invite: CompanyInviteSummary; token: string }> {
            const { data } = await api.post<{ invite: CompanyInviteSummary; token: string }>(`/invoicing/companies/${companyId}/invites`, payload);
            return data;
        },
        async revokeInvite(companyId: string, inviteId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/invites/${inviteId}`);
        },
        /** Company members - owner-only (docs/COMPANY_SHARING.md, "C5"). */
        async listMembers(companyId: string): Promise<CompanyMemberSummary[]> {
            const { data } = await api.get<ApiEnvelope<CompanyMemberSummary[]>>(`/invoicing/companies/${companyId}/members`);
            return data.data ?? [];
        },
        async revokeMember(companyId: string, memberId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/members/${memberId}`);
        },
    },
    /** User-level extra company slot purchases (one-off, Pro only). */
    companySlots: {
        async purchase<T = unknown>(slots: number): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>('/invoicing/company-slots/purchase', { slots });
            return data.data;
        },
    },
    /** Invite acceptance - reachable by any authenticated user (the invitee). */
    invites: {
        async preview(token: string): Promise<CompanyInvitePreview> {
            const { data } = await api.get<CompanyInvitePreview>(`/invoicing/invites/${token}`);
            return data;
        },
        async accept(token: string): Promise<{ company_id: string; role: string }> {
            const { data } = await api.post<{ company_id: string; role: string }>(`/invoicing/invites/${token}/accept`);
            return data;
        },
    },
    contacts: {
        async list<T = unknown>(companyId: string, params: ContactListParams = {}): Promise<{ data: T[]; meta: ContactListMeta }> {
            const { data } = await api.get<{ data?: T[]; meta?: ContactListMeta }>(`/invoicing/companies/${companyId}/contacts`, { params });
            return { data: data.data ?? [], meta: data.meta ?? {} };
        },
        async get<T = unknown>(companyId: string, contactId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/contacts/${contactId}`);
            return data.data;
        },
        async create<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/contacts`, payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, contactId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/contacts/${contactId}`, payload);
            return data.data;
        },
        async delete(companyId: string, contactId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/contacts/${contactId}`);
        },
        async bulk<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<T>(`/invoicing/companies/${companyId}/contacts/bulk`, payload);
            return data;
        },
        async bulkExport(companyId: string, payload: Record<string, unknown>): Promise<Blob> {
            const { data } = await api.post<Blob>(`/invoicing/companies/${companyId}/contacts/bulk`, payload, { responseType: 'blob' });
            return data;
        },
        import: spreadsheetImportGroup('contacts/import'),
    },
    documents: {
        async list<T = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/documents`, { params });
            return data.data ?? [];
        },
        /** Paginated variant: Laravel paginator fields live next to data. */
        async listPaged<T = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<PagedList<T>> {
            const { data } = await api.get<PagedList<T>>(`/invoicing/companies/${companyId}/documents`, { params });
            return data;
        },
        async get<T = unknown>(companyId: string, documentId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/${documentId}`);
            return data.data;
        },
        async create<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents`, payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, documentId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/${documentId}`, payload);
            return data.data;
        },
        async delete(companyId: string, documentId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/documents/${documentId}`);
        },
        // Lifecycle actions (issue/cancel/mark-paid/...) share one POST shape
        action: documentAction,
        async history<T = unknown>(companyId: string, documentId: string): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/documents/${documentId}/history`);
            return data.data ?? [];
        },
        async creditNoteFromInvoice<T = unknown>(companyId: string, invoiceId: string): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/credit-note-from-invoice`, { invoice_id: invoiceId });
            return data.data;
        },
        async emailPreview<T = unknown>(companyId: string, documentId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/${documentId}/email-preview`);
            return data.data;
        },
        async sendEmail<T = unknown>(companyId: string, documentId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/${documentId}/send-email`, payload);
            return data.data;
        },
        async bulk<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<T>(`/invoicing/companies/${companyId}/documents/bulk`, payload);
            return data;
        },
        async bulkExport(companyId: string, payload: Record<string, unknown>, signal?: AbortSignal): Promise<Blob> {
            const { data } = await api.post<Blob>(`/invoicing/companies/${companyId}/documents/bulk`, payload, { responseType: 'blob', signal });
            return data;
        },
        import: spreadsheetImportGroup('documents/import'),
        efaktura: {
            async compliance<T = unknown>(companyId: string, documentId: string): Promise<T[]> {
                const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/documents/${documentId}/efaktura/compliance`);
                return data.data ?? [];
            },
            async refresh(companyId: string, documentId: string): Promise<void> {
                await api.post(`/invoicing/companies/${companyId}/documents/${documentId}/efaktura/compliance/refresh`);
            },
            async send<T = unknown>(companyId: string, documentId: string): Promise<T> {
                const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/${documentId}/efaktura/send`);
                return data.data ?? ({} as T);
            },
        },
    },
    expenses: {
        async list<T = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/expenses`, { params });
            return data.data ?? [];
        },
        /** Paginated variant: Laravel paginator fields live next to data. */
        async listPaged<T = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<PagedList<T>> {
            const { data } = await api.get<PagedList<T>>(`/invoicing/companies/${companyId}/expenses`, { params });
            return data;
        },
        async get<T = unknown>(companyId: string, expenseId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/${expenseId}`);
            return data.data;
        },
        async create<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses`, payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, expenseId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/${expenseId}`, payload);
            return data.data;
        },
        async delete(companyId: string, expenseId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/expenses/${expenseId}`);
        },
        action: expenseAction,
        async history<T = unknown>(companyId: string, expenseId: string): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/expenses/${expenseId}/history`);
            return data.data ?? [];
        },
        async uploadAttachment(companyId: string, expenseId: string, formData: FormData): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/expenses/${expenseId}/attachment`, formData);
        },
        async deleteAttachment<T = unknown>(companyId: string, expenseId: string, attachmentId: string): Promise<T> {
            const { data } = await api.delete<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/${expenseId}/attachments/${attachmentId}`);
            return data.data;
        },
        async detectIsdoc<T = unknown>(companyId: string, formData: FormData): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/detect-isdoc`, formData);
            return data.data;
        },
        /** Returns the full body: callers read both data (draft) and quota. */
        async extract<T = unknown>(companyId: string, formData: FormData): Promise<{ data: T; quota?: unknown }> {
            const { data } = await api.post<{ data: T; quota?: unknown }>(`/invoicing/companies/${companyId}/expenses/extract`, formData);
            return data;
        },
        async isdocQuota<T = unknown>(companyId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/isdoc-extract-quota`);
            return data.data;
        },
        async purchaseIsdocPack<T = unknown>(companyId: string, credits: number): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/isdoc-packs/purchase`, { credits });
            return data.data;
        },
        async bulk<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<T>(`/invoicing/companies/${companyId}/expenses/bulk`, payload);
            return data;
        },
        async bulkExport(companyId: string, payload: Record<string, unknown>): Promise<Blob> {
            const { data } = await api.post<Blob>(`/invoicing/companies/${companyId}/expenses/bulk`, payload, { responseType: 'blob' });
            return data;
        },
        import: spreadsheetImportGroup('expenses/import/excel'),
        importAttachments: {
            async preview<T = unknown>(companyId: string, formData: FormData): Promise<T> {
                const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/import/attachments/preview`, formData);
                return data.data;
            },
            async run<T = unknown>(companyId: string, formData: FormData): Promise<T> {
                const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/import/attachments`, formData);
                return data.data;
            },
        },
    },
    stockItems: {
        async list<T = unknown, M = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<{ data: T[]; meta: M | null }> {
            const { data } = await api.get<{ data?: T[]; meta?: M }>(`/invoicing/companies/${companyId}/stock-items`, { params });
            return { data: data.data ?? [], meta: data.meta ?? null };
        },
        async get<T = unknown>(companyId: string, stockItemId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/stock-items/${stockItemId}`);
            return data.data;
        },
        async create<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/stock-items`, payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, stockItemId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/stock-items/${stockItemId}`, payload);
            return data.data;
        },
        async delete(companyId: string, stockItemId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/stock-items/${stockItemId}`);
        },
        async search<T = unknown>(companyId: string, params: Record<string, unknown>): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/stock-items/search`, { params });
            return data.data ?? [];
        },
        async transfer(companyId: string, stockItemId: string, payload: Record<string, unknown>): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/stock-items/${stockItemId}/transfer`, payload);
        },
        import: spreadsheetImportGroup('stock-items/import'),
    },
    warehouses: {
        async list<T = unknown>(companyId: string, params?: Record<string, unknown>): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/warehouses`, { params });
            return data.data ?? [];
        },
        async get<T = unknown>(companyId: string, warehouseId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/warehouses/${warehouseId}`);
            return data.data;
        },
        async create<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/warehouses`, payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, warehouseId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/warehouses/${warehouseId}`, payload);
            return data.data;
        },
        async delete(companyId: string, warehouseId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/warehouses/${warehouseId}`);
        },
    },
    numberSeries: {
        async list<T = unknown>(companyId: string): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/number-series`);
            return data.data ?? [];
        },
        async create(companyId: string, payload: Record<string, unknown>): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/number-series`, payload);
        },
        async update(companyId: string, seriesId: string, payload: Record<string, unknown>): Promise<void> {
            await api.patch(`/invoicing/companies/${companyId}/number-series/${seriesId}`, payload);
        },
        async delete(companyId: string, seriesId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/number-series/${seriesId}`);
        },
        async preview<T = unknown>(companyId: string, params: Record<string, unknown>): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/number-series/preview`, { params });
            return data.data;
        },
    },
    // Store-scoped series bridge for local-first numbering; responses carry a
    // top-level error field, so the full body is returned unwrapped.
    storeNumberSeries: {
        async preview<T = unknown>(storeId: string, params: Record<string, unknown>): Promise<{ data?: T; error?: string }> {
            const { data } = await api.get<{ data?: T; error?: string }>(`/invoicing/stores/${storeId}/number-series/preview`, { params });
            return data;
        },
        async reserve<T = unknown>(storeId: string, payload: Record<string, unknown>): Promise<{ data?: T; error?: string }> {
            const { data } = await api.post<{ data?: T; error?: string }>(`/invoicing/stores/${storeId}/number-series/reserve`, payload);
            return data;
        },
    },
    // Server number allocator (audit F3) - company-scoped, store-independent.
    numberAllocator: {
        async reserve<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/number-allocator/reserve`, payload);
            return data.data;
        },
        async confirm<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/number-allocator/confirm`, payload);
            return data.data;
        },
        async void<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/number-allocator/void`, payload);
            return data.data;
        },
        async release<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/number-allocator/release`, payload);
            return data.data;
        },
        async status<T = unknown>(companyId: string, params: Record<string, unknown>): Promise<T | null> {
            const { data } = await api.get<ApiEnvelope<T | null>>(`/invoicing/companies/${companyId}/number-allocator/status`, { params });
            return data.data ?? null;
        },
    },
    recurringProfiles: {
        async list<T = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/recurring-profiles`, { params });
            return data.data ?? [];
        },
        async get<T = unknown>(companyId: string, profileId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/recurring-profiles/${profileId}`);
            return data.data;
        },
        async create<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/recurring-profiles`, payload);
            return data.data;
        },
        async update<T = unknown>(companyId: string, profileId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.patch<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/recurring-profiles/${profileId}`, payload);
            return data.data;
        },
        async delete(companyId: string, profileId: string): Promise<void> {
            await api.delete(`/invoicing/companies/${companyId}/recurring-profiles/${profileId}`);
        },
        async generate<T = unknown>(companyId: string, profileId: string): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/recurring-profiles/${profileId}/generate`);
            return data.data;
        },
    },
    bankTransactions: {
        async list<T = unknown>(companyId: string, params: Record<string, unknown> = {}): Promise<{ data: T[]; meta?: { summary?: unknown; last_page?: number } }> {
            const { data } = await api.get<{ data?: T[]; meta?: { summary?: unknown; last_page?: number } }>(`/invoicing/companies/${companyId}/bank-transactions`, { params });
            return { data: data.data ?? [], meta: data.meta };
        },
        async batches<T = unknown>(companyId: string): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/bank-transactions/batches`);
            return data.data ?? [];
        },
        async inboundEmail<T = unknown>(companyId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/bank-transactions/inbound-email`);
            return data.data;
        },
        async import<T = unknown>(companyId: string, formData: FormData): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/bank-transactions/import`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return data.data;
        },
        async autoMatchBatch(companyId: string, batchId: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/bank-transactions/batches/${batchId}/auto-match`);
        },
        async suggestions<T = unknown>(companyId: string, transactionId: string): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/bank-transactions/${transactionId}/suggestions`);
            return data.data ?? [];
        },
        async match(companyId: string, transactionId: string, businessDocumentId: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/bank-transactions/${transactionId}/match`, { business_document_id: businessDocumentId });
        },
        async ignore(companyId: string, transactionId: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/bank-transactions/${transactionId}/ignore`);
        },
        async unmatch(companyId: string, transactionId: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/bank-transactions/${transactionId}/unmatch`);
        },
        async createExpense(companyId: string, transactionId: string, payload: Record<string, unknown>): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/bank-transactions/${transactionId}/create-expense`, payload);
        },
    },
    wise: {
        async status<T = unknown>(companyId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/wise/status`);
            return data.data;
        },
        async connect(companyId: string, wiseApiToken: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/wise/connect`, { wise_api_token: wiseApiToken });
        },
        async sync<T = unknown>(companyId: string, payload: Record<string, unknown> = {}): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/wise/sync`, payload);
            return data.data;
        },
    },
    efaktura: {
        async pollInbound<T = unknown>(companyId: string): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/efaktura/poll-inbound`);
            return data.data ?? ({} as T);
        },
        async testConnection<T = unknown>(companyId: string, payload: Record<string, unknown> = {}): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/efaktura/test-connection`, payload);
            return data.data ?? ({} as T);
        },
        // Latest compliance status per document id for the list badge.
        async complianceBulk<T = unknown>(companyId: string, documentIds: string[]): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/efaktura/compliance-bulk`, {
                document_ids: documentIds,
            });
            return data.data ?? ({} as T);
        },
        // Local-first variant - credentials live in Evolu, so they travel in
        // the body and nothing is persisted server-side.
        async testConnectionEphemeral<T = unknown>(payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>('/invoicing/ephemeral/efaktura/test-connection', payload);
            return data.data ?? ({} as T);
        },
        // Local-first inbox of received e-invoices parked on the bridge company.
        async inboxList<T = unknown>(companyId: string): Promise<T[]> {
            const { data } = await api.get<ApiEnvelope<T[]>>(`/invoicing/companies/${companyId}/efaktura/inbox`);
            return data.data ?? [];
        },
        async inboxDetail<T = unknown>(companyId: string, receiptId: string): Promise<T> {
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/efaktura/inbox/${receiptId}`);
            return data.data;
        },
        async inboxImported(companyId: string, receiptId: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/efaktura/inbox/${receiptId}/imported`);
        },
        async inboxDismiss(companyId: string, receiptId: string): Promise<void> {
            await api.post(`/invoicing/companies/${companyId}/efaktura/inbox/${receiptId}/dismiss`);
        },
    },
    usSalesTax: {
        async preview<T = unknown>(companyId: string, payload: Record<string, unknown>): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/us-sales-tax/preview`, payload);
            return data.data;
        },
    },
    integrationInbox: {
        async deeplink<T = unknown>(params: URLSearchParams | Record<string, string>): Promise<T> {
            const qs = params instanceof URLSearchParams ? params.toString() : new URLSearchParams(params).toString();
            const { data } = await api.get<ApiEnvelope<T>>(`/invoicing/integration-inbox/deeplink?${qs}`);
            return data.data;
        },
    },
    registry: {
        async coverage<T = unknown>(): Promise<T | null> {
            const { data } = await api.get<ApiEnvelope<T | null>>('/invoicing/company-registry/coverage');
            return data.data ?? null;
        },
        async search<T = unknown>(params: { q: string; country: string; limit?: number }): Promise<T | null> {
            const { data } = await api.get<ApiEnvelope<T | null>>('/invoicing/company-registry/search', { params });
            return data.data ?? null;
        },
        async entity<T = unknown>(entityId: string, country: string): Promise<T | null> {
            const { data } = await api.get<ApiEnvelope<T | null>>(`/invoicing/company-registry/entities/${encodeURIComponent(entityId)}`, { params: { country } });
            return data.data ?? null;
        },
    },
};

/** Excel-style import trio shared by contacts/expenses/stock-items/documents. */
function spreadsheetImportGroup(basePath: string) {
    return {
        async preview<T = unknown>(companyId: string, formData: FormData): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/${basePath}/preview`, formData);
            return data.data;
        },
        async run<T = unknown>(companyId: string, formData: FormData): Promise<T> {
            const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/${basePath}`, formData);
            return data.data;
        },
        async example(companyId: string): Promise<Blob> {
            const { data } = await api.get<Blob>(`/invoicing/companies/${companyId}/${basePath}/example`, { responseType: 'blob' });
            return data;
        },
    };
}

type DocumentLifecycleAction = 'issue' | 'cancel' | 'mark-paid' | 'unmark-paid' | 'duplicate' | 'approve-quote' | 'reject-quote' | 'create-invoice-from-quote' | 'create-final-invoice';

async function documentAction<T = unknown>(companyId: string, documentId: string, action: DocumentLifecycleAction): Promise<T> {
    const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/documents/${documentId}/${action}`);
    return data.data ?? ({} as T);
}

type ExpenseLifecycleAction = 'mark-paid' | 'unmark-paid' | 'duplicate';

async function expenseAction<T = unknown>(companyId: string, expenseId: string, action: ExpenseLifecycleAction): Promise<T> {
    const { data } = await api.post<ApiEnvelope<T>>(`/invoicing/companies/${companyId}/expenses/${expenseId}/${action}`);
    return data.data ?? ({} as T);
}

// Documentation API (locale ensures correct language content from backend)
export const documentationApi = {
    index: (params?: { category_id?: string; search?: string; locale?: string }) =>
        api.get('/documentation', { params }),
    show: (slug: string, params?: { locale?: string }) =>
        api.get(`/documentation/${slug}`, { params: params ?? {} }),
};

// FAQ API
export const faqApi = {
    index: (params?: { category_id?: string; search?: string }) => 
        api.get('/faq', { params }),
    show: (slug: string) => 
        api.get(`/faq/${slug}`),
    markHelpful: (slug: string) => 
        api.post(`/faq/${slug}/helpful`),
};

// Admin FAQ API
export const adminFaqApi = {
    items: {
        index: (params?: { category_id?: string; is_published?: boolean; search?: string }) => 
            api.get('/admin/faq/items', { params }),
        show: (id: string) => 
            api.get(`/admin/faq/items/${id}`),
        create: (data: Record<string, unknown>) => 
            api.post('/admin/faq/items', data),
        update: (id: string, data: Record<string, unknown>) => 
            api.put(`/admin/faq/items/${id}`, data),
        delete: (id: string) => 
            api.delete(`/admin/faq/items/${id}`),
    },
    categories: {
        index: () => 
            api.get('/admin/faq/categories'),
        show: (id: string) => 
            api.get(`/admin/faq/categories/${id}`),
        create: (data: Record<string, unknown>) => 
            api.post('/admin/faq/categories', data),
        update: (id: string, data: Record<string, unknown>) => 
            api.put(`/admin/faq/categories/${id}`, data),
        delete: (id: string) => 
            api.delete(`/admin/faq/categories/${id}`),
    },
};

export const adminRegwatchApi = {
    changes: {
        index: (params?: { status?: string; source_id?: string; page?: number }) =>
            api.get('/admin/regwatch/changes', { params }),
        show: (id: string) =>
            api.get(`/admin/regwatch/changes/${id}`),
        updateStatus: (id: string, status: string) =>
            api.put(`/admin/regwatch/changes/${id}/status`, { status }),
    },
    sources: {
        index: () =>
            api.get('/admin/regwatch/sources'),
    },
    jurisdictions: {
        index: () =>
            api.get('/admin/regwatch/jurisdictions'),
    },
    rules: {
        index: (params?: { jurisdiction_id?: string; topic?: string; verified?: string; page?: number }) =>
            api.get('/admin/regwatch/rules', { params }),
        show: (id: string) =>
            api.get(`/admin/regwatch/rules/${id}`),
        update: (id: string, data: Record<string, unknown>) =>
            api.put(`/admin/regwatch/rules/${id}`, data),
    },
};

export const adminEfakturaApi = {
    cpdsProviders: {
        index: () =>
            api.get('/admin/efaktura/cpds-providers'),
        store: (data: Record<string, unknown>) =>
            api.post('/admin/efaktura/cpds-providers', data),
        update: (id: string, data: Record<string, unknown>) =>
            api.put(`/admin/efaktura/cpds-providers/${id}`, data),
        destroy: (id: string) =>
            api.delete(`/admin/efaktura/cpds-providers/${id}`),
    },
};

export default api;








