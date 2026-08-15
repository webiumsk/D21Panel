import api, { invoicingApi } from "@/services/api";
import { matchCompanyByIdentity, normalizeCompanyIdentityKey } from "@/evolu/duplicateCompanies";
import { clearCompanyUnbridged } from "@/evolu/bridgeCompanyCache";
import { allDocumentSnapshotsQuery } from "./client";
import {
    buildIssuedSnapshotContentV1,
    insertIssuedDocumentSnapshot,
    latestSnapshotRowForDocument,
    parseIssuedSnapshotRow,
    type EvoluDocumentSnapshotRow,
    type IssuedDocumentSnapshotV1,
} from "./documentSnapshotCrud";
import { normalizeIsoCountryCode } from "@/utils/isoCountryCode";
import { isAbortError } from "@/utils/abortError";
import type { DocumentSavePayload } from "./documentCrud";
import type { CompanyId, DocumentId } from "./schema";
import { resolveLocalEmailSettingsForBridge } from "./companySettingsCrud";
import type { useLocalInvoiceDocumentSupport } from "@/composables/useLocalInvoiceDocument";
import type { AxiosResponse } from "axios";
import { toAppRows } from "./queryLoad";

export type EphemeralSnapshotPayload = {
    company: Record<string, unknown>;
    contact: Record<string, unknown> | null;
    document: Record<string, unknown>;
    lines: DocumentSavePayload["lines"];
    store_id?: string;
    evolu_document_id?: string;
    btcpay_checkout_link?: string;
};

export type EphemeralSnapshotOptions = {
    storeId?: string | null;
    evoluDocumentId?: string | null;
    btcpayCheckoutLink?: string | null;
};

const EPHEMERAL_PDF_PATH = "/invoicing/ephemeral/pdf";
const EPHEMERAL_EMAIL_PREVIEW_PATH = "/invoicing/ephemeral/email-preview";
const EPHEMERAL_SEND_EMAIL_PATH = "/invoicing/ephemeral/send-email";
const EPHEMERAL_EMAIL_SETTINGS_TEST_SMTP_PATH = "/invoicing/ephemeral/email-settings/test-smtp";
const EPHEMERAL_ISDOC_PATH = "/invoicing/ephemeral/isdoc";
const EPHEMERAL_UBL_PATH = "/invoicing/ephemeral/ubl";
const EPHEMERAL_BTCPAY_CHECKOUT_PATH = "/invoicing/ephemeral/btcpay-checkout";
const EPHEMERAL_EFAKTURA_BRIDGE_PATH = "/invoicing/ephemeral/efaktura/bridge";
const EPHEMERAL_EFAKTURA_STATUS_PATH = "/invoicing/ephemeral/efaktura/status";
const EPHEMERAL_EFAKTURA_SEND_PATH = "/invoicing/ephemeral/efaktura/send";
const EPHEMERAL_EFAKTURA_REFRESH_PATH = "/invoicing/ephemeral/efaktura/refresh";
const EPHEMERAL_BULK_PDF_ZIP_PATH = "/invoicing/ephemeral/bulk/pdf-zip";
const EPHEMERAL_BULK_PDF_MERGE_PATH = "/invoicing/ephemeral/bulk/pdf-merge";

function companyScopedEphemeralPdfPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/pdf`;
}

function companyScopedEphemeralEmailPreviewPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/email-preview`;
}

function companyScopedEphemeralSendEmailPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/send-email`;
}

function companyScopedEphemeralEmailSettingsTestSmtpPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/email-settings/ephemeral/test-smtp`;
}

function companyScopedEphemeralIsdocPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/isdoc`;
}

function companyScopedEphemeralUblPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/ubl`;
}

function companyScopedEphemeralBtcpayCheckoutPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/btcpay-checkout`;
}

function companyScopedEphemeralEfakturaSendPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/efaktura/send`;
}

function companyScopedEphemeralEfakturaRefreshPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/efaktura/refresh`;
}

function companyScopedEphemeralBulkPdfZipPath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/bulk/pdf-zip`;
}

function companyScopedEphemeralBulkPdfMergePath(bridgeCompanyId: string): string {
    return `/invoicing/companies/${bridgeCompanyId}/documents/ephemeral/bulk/pdf-merge`;
}

function shouldFallbackEphemeralRoute(error: unknown): boolean {
    const status = (error as { response?: { status?: number } })?.response?.status;
    return status === 404 || status === 405;
}

export async function parseAxiosBlobError(error: unknown): Promise<string | null> {
    const response = (error as { response?: { data?: unknown } })?.response;
    if (!response?.data) return null;

    if (response.data instanceof Blob) {
        try {
            const text = await response.data.text();
            const parsed = JSON.parse(text) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const firstFieldError = parsed.errors
                ? Object.values(parsed.errors).flat().find(Boolean)
                : undefined;
            return parsed.message || firstFieldError || null;
        } catch {
            return null;
        }
    }

    const data = response.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    if (!data) return null;
    const firstFieldError = data.errors
        ? Object.values(data.errors).flat().find(Boolean)
        : undefined;
    return data.message || firstFieldError || null;
}

async function postWithCompanyScopedFallback<T>(
    companyLessPath: string,
    companyScopedPath: string | null,
    body: unknown,
    config?: { responseType?: "blob"; signal?: AbortSignal },
): Promise<AxiosResponse<T>> {
    try {
        return await api.post<T>(companyLessPath, body, config);
    } catch (error: unknown) {
        // A user-initiated abort must not fall back to the second route.
        if (isAbortError(error)) {
            throw error;
        }
        if (companyScopedPath && shouldFallbackEphemeralRoute(error)) {
            try {
                return await api.post<T>(companyScopedPath, body, config);
            } catch (scopedError: unknown) {
                const message = await parseAxiosBlobError(scopedError);
                if (message) {
                    throw new Error(message);
                }
                throw scopedError;
            }
        }
        const message = await parseAxiosBlobError(error);
        if (message) {
            throw new Error(message);
        }
        throw error;
    }
}

/** Resolve a server-owned company id for legacy company-scoped ephemeral bridge routes. */
export async function resolveEphemeralBridgeCompanyId(match?: {
    legal_name?: string | null;
    registration_number?: string | null;
}): Promise<string | null> {
    try {
        const rows = await invoicingApi.companies.list<{
            id: string;
            legal_name: string;
            registration_number?: string | null;
        }>();

        if (match?.legal_name) {
            const found = matchCompanyByIdentity(rows, {
                legal_name: match.legal_name,
                registration_number: match.registration_number ?? null,
            });
            if (found) {
                return found.id;
            }
        }

        return rows[0]?.id ?? null;
    } catch {
        return null;
    }
}

export type StoreLinkSyncResult = "synced" | "no_bridge_company" | "failed";

/**
 * Mirror the Evolu company's linkedStoreId to the server bridge company
 * (stores.company_id). Server-side features - number-series preview/reserve,
 * WooCommerce invoicing - resolve the link through $store->company, so a
 * local-only link leaves them answering 422 store_not_linked.
 *
 * Matches the bridge company by identity ONLY (legal name + registration
 * number, with a name-only fallback when one side is missing the registration
 * number and the name is unambiguous - see matchCompanyByIdentity). No
 * first-row fallback here: linking a store to the wrong server company would
 * cross-wire document sequences.
 */
export async function syncLinkedStoreToServerBridge(
    match: { legal_name?: string | null; registration_number?: string | null },
    storeId: string | null,
): Promise<StoreLinkSyncResult> {
    if (!match.legal_name) {
        return "no_bridge_company";
    }
    try {
        const rows = await invoicingApi.companies.list<{
            id: string;
            legal_name: string;
            registration_number?: string | null;
        }>();
        const found = matchCompanyByIdentity(rows, {
            legal_name: match.legal_name,
            registration_number: match.registration_number ?? null,
        });
        if (!found) {
            return "no_bridge_company";
        }
        await invoicingApi.companies.updateStores(found.id, storeId ? [storeId] : []);
        // The bridge company exists after all: re-enable server number-series
        // calls that a previous no_bridge_company answer suppressed.
        clearCompanyUnbridged(
            normalizeCompanyIdentityKey(match.legal_name, match.registration_number ?? null),
        );
        return "synced";
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn(
                "[invoicing] store link sync to server bridge failed:",
                error instanceof Error ? error.message : error,
            );
        }
        return "failed";
    }
}

export function emailSettingsForEphemeralSnapshot(
    company: Record<string, unknown> | null,
): Record<string, unknown> | undefined {
    const raw = company?.email_settings;
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) {
        return undefined;
    }

    const settings = raw as Record<string, unknown>;
    const out: Record<string, unknown> = {};

    if (typeof settings.delivery_method === "string") {
        out.delivery_method = settings.delivery_method;
    }
    if (settings.templates && typeof settings.templates === "object") {
        out.templates = settings.templates;
    }
    if (settings.smtp && typeof settings.smtp === "object") {
        const smtp = settings.smtp as Record<string, unknown>;
        out.smtp = {
            username: smtp.username,
            host: smtp.host,
            port: smtp.port,
            from_name: smtp.from_name,
            encryption: smtp.encryption,
            use_smtp_email_as_from: smtp.use_smtp_email_as_from,
            ...(typeof smtp.password === "string" && smtp.password ? { password: smtp.password } : {}),
        };
    }

    return Object.keys(out).length > 0 ? out : undefined;
}

export function resolveEphemeralAmountPaid(value: unknown): number | undefined {
    if (value == null || value === "") {
        return undefined;
    }
    const amount = typeof value === "number" ? value : parseFloat(String(value));
    if (!Number.isFinite(amount)) {
        return undefined;
    }
    return amount;
}

export function buildEphemeralSnapshot(
    company: Record<string, unknown> | null,
    contact: Record<string, unknown> | null,
    document: Record<string, unknown>,
    lines: DocumentSavePayload["lines"],
    options: EphemeralSnapshotOptions = {},
): EphemeralSnapshotPayload {
    const rawAppSettings = (company?.app_settings ?? {}) as Record<string, unknown>;
    const appSettings: Record<string, unknown> = {
        show_pay_by_square: rawAppSettings.show_pay_by_square ?? true,
        efaktura_enabled: rawAppSettings.efaktura_enabled,
        efaktura_auto_send: rawAppSettings.efaktura_auto_send,
        efaktura_sapi_base_url: rawAppSettings.efaktura_sapi_base_url,
        efaktura_peppol_participant_id: rawAppSettings.efaktura_peppol_participant_id,
        efaktura_sapi_client_id: rawAppSettings.efaktura_sapi_client_id,
    };
    const secret = rawAppSettings.efaktura_sapi_client_secret;
    if (typeof secret === "string" && secret !== "") {
        appSettings.efaktura_sapi_client_secret = secret;
    }

    const amountPaid = resolveEphemeralAmountPaid(document.amount_paid);
    const emailSettings = emailSettingsForEphemeralSnapshot(company);

    return {
        company: {
            legal_name: company?.legal_name,
            trade_name: company?.trade_name,
            registration_number: company?.registration_number,
            tax_id: company?.tax_id,
            vat_number: company?.vat_number,
            street: company?.street,
            city: company?.city,
            postal_code: company?.postal_code,
            country: normalizeIsoCountryCode(company?.country as string | null | undefined),
            state_region: company?.state_region,
            iban: company?.iban,
            bic: company?.bic,
            bank_name: company?.bank_name,
            bank_account: company?.bank_account,
            bank_code: company?.bank_code,
            default_currency: company?.default_currency,
            jurisdiction: company?.jurisdiction,
            vat_payer: company?.vat_payer,
            // vat_status was silently missing - the server PDF fell back to
            // vat_payer and mislabeled §7a companies as full payers.
            vat_status: company?.vat_status,
            vat_rate_default: company?.vat_rate_default,
            register_court: company?.register_court,
            register_number: company?.register_number,
            managing_directors: company?.managing_directors,
            supervisory_board_chair: company?.supervisory_board_chair,
            legal_footer_note: company?.legal_footer_note,
            issuer_name: company?.issuer_name,
            issuer_phone: company?.issuer_phone,
            issuer_email: company?.issuer_email,
            website: company?.website,
            app_settings: appSettings,
            ...(emailSettings ? { email_settings: emailSettings } : {}),
            ...(company?.logo_url ? { logo_url: company.logo_url } : {}),
            ...(company?.signature_stamp_url ? { signature_stamp_url: company.signature_stamp_url } : {}),
        },
        contact: contact
            ? {
                  name: contact.name,
                  email: contact.email,
                  registration_number: contact.registration_number,
                  tax_id: contact.tax_id,
                  vat_id: contact.vat_id,
                  street: contact.street,
                  city: contact.city,
                  postal_code: contact.postal_code,
                  state_region: contact.state_region,
                  country: normalizeIsoCountryCode(contact.country as string | null | undefined),
              }
            : null,
        document: {
            type: document.type,
            status: document.status,
            title: document.title,
            number: document.number,
            variable_symbol: document.variable_symbol,
            constant_symbol: document.constant_symbol,
            specific_symbol: document.specific_symbol,
            issue_date: document.issue_date,
            delivery_date: document.delivery_date,
            due_date: document.due_date,
            currency: document.currency,
            note_above_lines: document.note_above_lines,
            note_footer: document.note_footer,
            internal_note: document.internal_note,
            pdf_locale: document.pdf_locale,
            pdf_bank_qr: document.pdf_bank_qr ?? null,
            pdf_show_signature: document.pdf_show_signature,
            pdf_show_payment_info: document.pdf_show_payment_info,
            payment_bank_enabled: document.payment_bank_enabled,
            payment_btc_enabled: document.payment_btc_enabled,
            discount_percent: document.discount_percent,
            ...(amountPaid !== undefined ? { amount_paid: amountPaid } : {}),
        },
        lines: lines.map((line) => ({
            name: line.name,
            description: line.description,
            quantity: line.quantity,
            unit: line.unit,
            unit_price: line.unit_price,
            line_discount_percent: line.line_discount_percent,
            tax_rate: line.tax_rate,
        })),
        ...(options.storeId ? { store_id: options.storeId } : {}),
        ...(options.evoluDocumentId ? { evolu_document_id: options.evoluDocumentId } : {}),
        ...(options.btcpayCheckoutLink ? { btcpay_checkout_link: options.btcpayCheckoutLink } : {}),
    };
}

export async function downloadEphemeralPdf(
    snapshot: EphemeralSnapshotPayload,
    filename: string,
    bridgeCompanyId?: string | null,
): Promise<void> {
    await downloadEphemeralBlob(
        EPHEMERAL_PDF_PATH,
        bridgeCompanyId ? companyScopedEphemeralPdfPath(bridgeCompanyId) : null,
        snapshot,
        filename,
    );
}

async function downloadEphemeralBlob(
    companyLessPath: string,
    companyScopedPath: string | null,
    snapshot: EphemeralSnapshotPayload,
    filename: string,
): Promise<void> {
    const res = await postWithCompanyScopedFallback<Blob>(
        companyLessPath,
        companyScopedPath,
        snapshot,
        { responseType: "blob" },
    );
    const blob = res.data as Blob;
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

export async function downloadEphemeralIsdoc(
    snapshot: EphemeralSnapshotPayload,
    filename: string,
    bridgeCompanyId?: string | null,
): Promise<void> {
    await downloadEphemeralBlob(
        EPHEMERAL_ISDOC_PATH,
        bridgeCompanyId ? companyScopedEphemeralIsdocPath(bridgeCompanyId) : null,
        snapshot,
        filename,
    );
}

export async function downloadEphemeralUbl(
    snapshot: EphemeralSnapshotPayload,
    filename: string,
    bridgeCompanyId?: string | null,
): Promise<void> {
    await downloadEphemeralBlob(
        EPHEMERAL_UBL_PATH,
        bridgeCompanyId ? companyScopedEphemeralUblPath(bridgeCompanyId) : null,
        snapshot,
        filename,
    );
}

export type EphemeralBtcpayCheckoutResult = {
    checkout_link: string | null;
    btcpay_invoice_id: string | null;
};

/**
 * Read-only lookup of an existing still-payable checkout - viewing an
 * invoice must never mint a BTCPay invoice (that is an explicit action via
 * fetchEphemeralBtcpayCheckout). Null when none exists; also null on 404
 * from a backend that predates the endpoint.
 */
export async function fetchExistingEphemeralBtcpayCheckout(
    snapshot: EphemeralSnapshotPayload,
    storeId: string,
    evoluDocumentId?: string | null,
): Promise<EphemeralBtcpayCheckoutResult | null> {
    const body = {
        ...snapshot,
        store_id: storeId,
        evolu_document_id: evoluDocumentId || undefined,
        document: {
            ...snapshot.document,
            payment_btc_enabled: true,
        },
    };

    try {
        const res = await api.post<{ data: EphemeralBtcpayCheckoutResult | null }>(
            `${EPHEMERAL_BTCPAY_CHECKOUT_PATH}/existing`,
            body,
        );
        return res.data.data ?? null;
    } catch (error: unknown) {
        const status = (error as { response?: { status?: number } })?.response?.status;
        if (status === 404) {
            return null;
        }
        throw error;
    }
}

export async function fetchEphemeralBtcpayCheckout(
    snapshot: EphemeralSnapshotPayload,
    storeId: string,
    evoluDocumentId?: string | null,
    bridgeCompanyId?: string | null,
): Promise<EphemeralBtcpayCheckoutResult> {
    const body = {
        ...snapshot,
        store_id: storeId,
        evolu_document_id: evoluDocumentId || undefined,
        document: {
            ...snapshot.document,
            payment_btc_enabled: true,
        },
    };

    try {
        const res = await postWithCompanyScopedFallback<{ data: EphemeralBtcpayCheckoutResult }>(
            EPHEMERAL_BTCPAY_CHECKOUT_PATH,
            bridgeCompanyId ? companyScopedEphemeralBtcpayCheckoutPath(bridgeCompanyId) : null,
            body,
        );

        return res.data.data;
    } catch (error: unknown) {
        const message = await parseAxiosBlobError(error);
        if (message) {
            throw new Error(message);
        }
        throw error;
    }
}

export async function previewEphemeralEmail(
    snapshot: EphemeralSnapshotPayload,
    bridgeCompanyId?: string | null,
): Promise<{
    to: string;
    subject: string;
    body: string;
    attachment_filename: string;
}> {
    const res = await postWithCompanyScopedFallback<{ data: {
        to: string;
        subject: string;
        body: string;
        attachment_filename: string;
    } }>(
        EPHEMERAL_EMAIL_PREVIEW_PATH,
        bridgeCompanyId ? companyScopedEphemeralEmailPreviewPath(bridgeCompanyId) : null,
        snapshot,
    );
    return res.data.data;
}

export async function sendEphemeralEmail(
    snapshot: EphemeralSnapshotPayload,
    fields: {
        to: string;
        cc?: string;
        bcc?: string;
        subject: string;
        body: string;
    },
    bridgeCompanyId?: string | null,
): Promise<{ email_sent_at?: string }> {
    const res = await postWithCompanyScopedFallback<{ data?: { email_sent_at?: string } }>(
        EPHEMERAL_SEND_EMAIL_PATH,
        bridgeCompanyId ? companyScopedEphemeralSendEmailPath(bridgeCompanyId) : null,
        {
            ...snapshot,
            to: fields.to,
            cc: fields.cc || undefined,
            bcc: fields.bcc || undefined,
            subject: fields.subject,
            body: fields.body,
        },
    );
    return res.data.data ?? {};
}

export async function testEphemeralEmailSmtp(
    company: Record<string, unknown>,
    emailSettings: Record<string, unknown>,
    to: string,
    bridgeCompanyId?: string | null,
): Promise<{ message: string }> {
    const res = await postWithCompanyScopedFallback<{ message: string }>(
        EPHEMERAL_EMAIL_SETTINGS_TEST_SMTP_PATH,
        bridgeCompanyId ? companyScopedEphemeralEmailSettingsTestSmtpPath(bridgeCompanyId) : null,
        {
            to,
            company: {
                ...company,
                email_settings: emailSettings,
            },
        },
    );

    return { message: res.data.message ?? "Test email sent." };
}

export async function fetchEphemeralBtcpayStatus(
    evoluDocumentId: string,
    btcpayInvoiceId: string,
): Promise<{ status: string; paid_at: string | null; evolu_document_id: string }> {
    const res = await api.get("/invoicing/ephemeral/btcpay-status", {
        params: {
            evolu_document_id: evoluDocumentId,
            btcpay_invoice_id: btcpayInvoiceId,
        },
    });
    return res.data.data;
}

export type EphemeralEfakturaBridgeInfo = {
    configured: boolean;
    bridge_company_id: string | null;
    bridge_company_name: string | null;
};

export type EphemeralEfakturaComplianceRow = {
    id: string;
    provider: string;
    status: string;
    external_id: string | null;
    submitted_at: string | null;
    resolved_at?: string | null;
    response_payload?: Record<string, unknown> | null;
    message?: string | null;
};

export async function fetchEphemeralEfakturaBridge(): Promise<EphemeralEfakturaBridgeInfo> {
    const res = await api.get(EPHEMERAL_EFAKTURA_BRIDGE_PATH);
    return res.data.data;
}

export async function fetchEphemeralEfakturaStatus(
    evoluDocumentId: string,
): Promise<EphemeralEfakturaComplianceRow[]> {
    const res = await api.get(EPHEMERAL_EFAKTURA_STATUS_PATH, {
        params: { evolu_document_id: evoluDocumentId },
    });
    return Array.isArray(res.data?.data) ? res.data.data : [];
}

/** Latest submission per Evolu document id for the invoice-list badge. */
export async function fetchEphemeralEfakturaStatusBulk(
    evoluDocumentIds: string[],
): Promise<Record<string, EphemeralEfakturaComplianceRow>> {
    if (evoluDocumentIds.length === 0) {
        return {};
    }
    const res = await api.post(`${EPHEMERAL_EFAKTURA_STATUS_PATH}-bulk`, {
        evolu_document_ids: evoluDocumentIds,
    });
    const data = res.data?.data;
    return data && typeof data === "object" ? (data as Record<string, EphemeralEfakturaComplianceRow>) : {};
}

export async function sendEphemeralEfaktura(
    snapshot: EphemeralSnapshotPayload,
    evoluDocumentId: string,
    bridgeCompanyId?: string | null,
): Promise<EphemeralEfakturaComplianceRow> {
    const body = {
        ...snapshot,
        evolu_document_id: evoluDocumentId,
    };

    const res = await postWithCompanyScopedFallback<{ data: EphemeralEfakturaComplianceRow }>(
        EPHEMERAL_EFAKTURA_SEND_PATH,
        bridgeCompanyId ? companyScopedEphemeralEfakturaSendPath(bridgeCompanyId) : null,
        body,
    );

    return res.data.data;
}

export async function refreshEphemeralEfakturaStatus(
    evoluDocumentId: string,
    bridgeCompanyId?: string | null,
): Promise<EphemeralEfakturaComplianceRow[]> {
    const body = { evolu_document_id: evoluDocumentId };

    const res = await postWithCompanyScopedFallback<{ data: EphemeralEfakturaComplianceRow[] }>(
        EPHEMERAL_EFAKTURA_REFRESH_PATH,
        bridgeCompanyId ? companyScopedEphemeralEfakturaRefreshPath(bridgeCompanyId) : null,
        body,
    );

    return Array.isArray(res.data?.data) ? res.data.data : [];
}

export type EphemeralBulkDocumentItem = Omit<EphemeralSnapshotPayload, "company">;

export type EphemeralBulkRequestBody = {
    company: EphemeralSnapshotPayload["company"];
    documents: EphemeralBulkDocumentItem[];
};

function downloadResponseBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

export async function downloadEphemeralPdfZip(
    body: EphemeralBulkRequestBody,
    bridgeCompanyId?: string | null,
    options?: { signal?: AbortSignal },
): Promise<void> {
    const res = await postWithCompanyScopedFallback<Blob>(
        EPHEMERAL_BULK_PDF_ZIP_PATH,
        bridgeCompanyId ? companyScopedEphemeralBulkPdfZipPath(bridgeCompanyId) : null,
        body,
        { responseType: "blob", signal: options?.signal },
    );
    downloadResponseBlob(res.data as Blob, "invoices.zip");
}

export async function downloadEphemeralPdfMerge(
    body: EphemeralBulkRequestBody,
    bridgeCompanyId?: string | null,
    options?: { signal?: AbortSignal },
): Promise<void> {
    const res = await postWithCompanyScopedFallback<Blob>(
        EPHEMERAL_BULK_PDF_MERGE_PATH,
        bridgeCompanyId ? companyScopedEphemeralBulkPdfMergePath(bridgeCompanyId) : null,
        body,
        { responseType: "blob", signal: options?.signal },
    );
    downloadResponseBlob(res.data as Blob, "invoices-merged.pdf");
}

export async function buildBulkEphemeralRequest(
    localDoc: ReturnType<typeof useLocalInvoiceDocumentSupport>,
    companyId: string,
    documentIds: string[],
): Promise<{ body: EphemeralBulkRequestBody; bridgeCompanyId: string | null } | null> {
    await localDoc.refreshAll();

    const company = localDoc.companyApi(companyId);
    if (!company) return null;

    const emailSettings = resolveLocalEmailSettingsForBridge(
        localDoc.evolu,
        companyId as CompanyId,
        {},
    );

    const documents: EphemeralBulkDocumentItem[] = [];
    let companyPayload: EphemeralBulkRequestBody["company"] | null = null;

    for (const documentId of documentIds) {
        const doc = localDoc.documentApi(documentId as DocumentId);
        if (!doc) continue;

        const contactId = String(doc.company_contact_id || "");
        const contact = contactId
            ? localDoc.contactsForCompany(companyId).find((c) => c.id === contactId) ?? null
            : null;
        const payload = localDoc.payloadFromApiDocument(doc);
        const snapshot = buildEphemeralSnapshotFromApiDocument(
            company,
            contact,
            doc,
            payload.lines,
            emailSettings,
            documentId,
        );
        if (!companyPayload) {
            companyPayload = snapshot.company;
        }
        documents.push({
            contact: snapshot.contact,
            document: snapshot.document,
            lines: snapshot.lines,
        });
    }

    if (documents.length === 0 || !companyPayload) return null;

    const bridgeCompanyId = await resolveEphemeralBridgeCompanyId({
        legal_name: String(company.legal_name ?? ""),
        registration_number: (company.registration_number as string | null | undefined) ?? null,
    });

    return {
        bridgeCompanyId,
        body: {
            company: companyPayload,
            documents,
        },
    };
}

export function buildEphemeralSnapshotFromApiDocument(
    company: Record<string, unknown> | null,
    contact: Record<string, unknown> | null,
    doc: Record<string, unknown>,
    lines: DocumentSavePayload["lines"],
    emailSettingsOverride?: Record<string, unknown>,
    evoluDocumentId?: string | null,
): EphemeralSnapshotPayload {
    const companyForSnapshot = emailSettingsOverride && company
        ? { ...company, email_settings: emailSettingsOverride }
        : company;

    return buildEphemeralSnapshot(companyForSnapshot, contact, {
        type: doc.type,
        status: doc.status,
        title: doc.title,
        number: doc.number,
        variable_symbol: doc.variable_symbol,
        constant_symbol: doc.constant_symbol,
        specific_symbol: doc.specific_symbol,
        issue_date: doc.issue_date,
        delivery_date: doc.delivery_date,
        due_date: doc.due_date,
        currency: doc.currency,
        note_above_lines: doc.note_above_lines,
        note_footer: doc.note_footer,
        internal_note: doc.internal_note,
        pdf_locale: doc.pdf_locale,
        pdf_bank_qr: doc.pdf_bank_qr ?? null,
        pdf_show_signature: doc.pdf_show_signature,
        pdf_show_payment_info: doc.pdf_show_payment_info,
        payment_bank_enabled: doc.payment_bank_enabled,
        payment_btc_enabled: doc.payment_btc_enabled,
        discount_percent: doc.discount_percent,
        amount_paid: doc.amount_paid,
    }, lines, {
        storeId: typeof doc.store_id === 'string' ? doc.store_id : null,
        // Without the evolu id the server cannot find the document's
        // registered BTCPay checkout (and the keyless render never mints),
        // so list/bulk PDFs silently lost the BTC QR the detail PDF had.
        evoluDocumentId: evoluDocumentId ?? null,
    });
}

type LocalDocSupport = ReturnType<typeof useLocalInvoiceDocumentSupport>;

/**
 * Resolves the frozen issued snapshot for a non-draft document, backfilling
 * one (marked backfilled) for documents issued before F2. Returns null for
 * drafts and when no valid snapshot can be obtained - callers then render
 * from live rows as before.
 */
async function resolveFrozenIssuedContent(
    localDoc: LocalDocSupport,
    doc: Record<string, unknown>,
    company: Record<string, unknown> | null,
    contact: Record<string, unknown> | null,
    documentId: string,
): Promise<IssuedDocumentSnapshotV1 | null> {
    if (doc.status === "draft") return null;

    let rows: EvoluDocumentSnapshotRow[];
    try {
        rows = toAppRows<EvoluDocumentSnapshotRow>(
            await localDoc.evolu.loadQuery(allDocumentSnapshotsQuery),
        );
    } catch {
        // Query failure: fall back to live render. No backfill either - we
        // cannot tell whether a snapshot already exists.
        return null;
    }
    const latest = latestSnapshotRowForDocument(rows, documentId);
    if (latest) {
        const parsed = parseIssuedSnapshotRow(latest);
        return parsed.ok ? parsed.value : null;
    }

    // Legacy issued document (pre-F2): freeze the current live state once,
    // honestly marked as backfilled - it may not equal the issue-time state.
    if (!company) return null;
    const content = buildIssuedSnapshotContentV1({
        company,
        contact,
        document: doc,
        lines: (doc.lines as Record<string, unknown>[]) ?? [],
    });
    if (!content.ok) return null;
    insertIssuedDocumentSnapshot(localDoc.evolu, documentId as DocumentId, content.value, {
        backfilled: true,
    });
    return content.value;
}

/** Frozen snapshot content merged with live operational fields into render inputs. */
function renderInputsFromFrozen(
    frozen: IssuedDocumentSnapshotV1,
    liveCompany: Record<string, unknown> | null,
    liveDoc: Record<string, unknown>,
): {
    company: Record<string, unknown>;
    contact: Record<string, unknown> | null;
    document: Record<string, unknown>;
    lines: DocumentSavePayload["lines"];
} {
    return {
        company: {
            ...frozen.company,
            vat_rate_default: Number(frozen.company.vat_rate_default ?? 0) || 0,
            // Operational, not part of the frozen document content:
            app_settings: liveCompany?.app_settings,
            email_settings: liveCompany?.email_settings,
            logo_url: liveCompany?.logo_url,
            signature_stamp_url: liveCompany?.signature_stamp_url,
        },
        contact: frozen.contact ? { ...frozen.contact } : null,
        document: {
            ...frozen.document,
            status: liveDoc.status,
            amount_paid: liveDoc.amount_paid,
            // Operational payment routing, deliberately NOT frozen content:
            // which BTCPay store serves the checkout (without it the server
            // renders no BTC QR at all) and the bank-QR standard choice.
            store_id: liveDoc.store_id,
            pdf_bank_qr: liveDoc.pdf_bank_qr ?? null,
        },
        lines: frozen.lines.map((line) => ({
            name: line.name,
            description: line.description,
            quantity: Number(line.quantity) || 0,
            unit: line.unit ?? "ks",
            unit_price: Number(line.unit_price) || 0,
            line_discount_percent: Number(line.line_discount_percent) || 0,
            tax_rate: Number(line.tax_rate) || 0,
            company_stock_item_id: null,
            company_warehouse_id: null,
        })),
    };
}

export async function buildLocalDocumentEphemeralSnapshot(
    localDoc: LocalDocSupport,
    companyId: string,
    documentId: string,
): Promise<{ snapshot: EphemeralSnapshotPayload; bridgeCompanyId: string | null } | null> {
    await localDoc.refreshAll();

    const doc = localDoc.documentApi(documentId as DocumentId);
    if (!doc) return null;

    const company = localDoc.companyApi(companyId);
    const contactId = String(doc.company_contact_id || "");
    const contact = contactId
        ? localDoc.contactsForCompany(companyId).find((c) => c.id === contactId) ?? null
        : null;
    const emailSettings = resolveLocalEmailSettingsForBridge(
        localDoc.evolu,
        companyId as CompanyId,
        {},
    );
    const bridgeCompanyId = await resolveEphemeralBridgeCompanyId(
        company
            ? {
                legal_name: String(company.legal_name ?? ""),
                registration_number: (company.registration_number as string | null | undefined) ?? null,
            }
            : undefined,
    );

    // Issued documents render from their frozen snapshot (audit F2) so later
    // contact/company edits cannot rewrite them; drafts render live.
    const frozen = await resolveFrozenIssuedContent(localDoc, doc, company, contact, documentId);
    if (frozen) {
        const inputs = renderInputsFromFrozen(frozen, company, doc);
        return {
            bridgeCompanyId,
            snapshot: buildEphemeralSnapshotFromApiDocument(
                inputs.company,
                inputs.contact,
                inputs.document,
                inputs.lines,
                emailSettings,
                documentId,
            ),
        };
    }

    const payload = localDoc.payloadFromApiDocument(doc);
    return {
        bridgeCompanyId,
        snapshot: buildEphemeralSnapshotFromApiDocument(
            company,
            contact,
            doc,
            payload.lines,
            emailSettings,
            documentId,
        ),
    };
}
