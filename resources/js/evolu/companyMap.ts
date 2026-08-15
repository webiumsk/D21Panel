import type { CompanyId } from "./schema";
import { normalizeIsoCountryCode } from "@/utils/isoCountryCode";

/** Company shape expected by invoicing Vue components (server API compatible). */
export type InvoicingCompanyRecord = {
    id: string;
    legal_name: string;
    trade_name: string | null;
    jurisdiction: string;
    default_currency: string;
    registration_number: string | null;
    tax_id: string | null;
    vat_number: string | null;
    commercial_register: string | null;
    register_court: string | null;
    register_number: string | null;
    managing_directors: string | null;
    supervisory_board_chair: string | null;
    street: string | null;
    city: string | null;
    postal_code: string | null;
    state_region: string | null;
    country: string | null;
    iban: string | null;
    bic: string | null;
    bank_name: string | null;
    bank_account: string | null;
    bank_code: string | null;
    vat_payer: boolean;
    vat_status: string;
    vat_rate_default: number;
    vat_turnover_limit?: number;
    legal_footer_note: string | null;
    issuer_name: string | null;
    issuer_phone: string | null;
    issuer_email: string | null;
    website: string | null;
    invoice_number_prefix: string | null;
    stores: Array<{ id: string; name: string; default_currency?: string }>;
    contacts: unknown[];
    logo_url: string | null;
    signature_stamp_url: string | null;
    app_settings: Record<string, unknown>;
    email_settings: Record<string, unknown>;
};

export type EvoluCompanyRow = {
    id: CompanyId;
    legalName: string;
    tradeName: string | null;
    jurisdiction: string;
    defaultCurrency: string;
    registrationNumber: string | null;
    taxId: string | null;
    vatNumber: string | null;
    commercialRegister: string | null;
    registerCourt: string | null;
    registerNumber: string | null;
    managingDirectors: string | null;
    supervisoryBoardChair: string | null;
    street: string | null;
    city: string | null;
    postalCode: string | null;
    country: string | null;
    stateRegion: string | null;
    iban: string | null;
    bic: string | null;
    bankName: string | null;
    bankAccount: string | null;
    bankCode: string | null;
    vatPayer: 0 | 1 | null;
    vatStatus: string;
    vatRateDefault: string | null;
    vatTurnoverLimit?: string | null;
    legalFooterNote: string | null;
    issuerName: string | null;
    issuerPhone: string | null;
    issuerEmail: string | null;
    website: string | null;
    invoiceNumberPrefix: string | null;
    linkedStoreId: string | null;
    appSettingsJson: string | null;
    emailSettingsJson: string | null;
    logoDataUrl: string | null;
    signatureDataUrl: string | null;
};

function parseSettingsJson(json: string | null | undefined): Record<string, unknown> {
    if (!json?.trim()) return {};
    try {
        const parsed = JSON.parse(json);
        if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
            return parsed as Record<string, unknown>;
        }
    } catch {
        // ignore invalid JSON
    }
    return {};
}

function parseEmailSettingsJson(json: string | null | undefined): Record<string, unknown> {
    const settings = parseSettingsJson(json);
    if (settings.smtp && typeof settings.smtp === "object") {
        const smtp = { ...(settings.smtp as Record<string, unknown>) };
        if (smtp.password) {
            smtp.password_set = true;
        }
        delete smtp.password;
        settings.smtp = smtp;
    }
    return settings;
}

function sqliteBoolToBoolean(value: 0 | 1 | null | undefined): boolean {
    return value === 1;
}

export function evoluCompanyToApi(
    row: EvoluCompanyRow,
    storeLookup?: (id: string) => { id: string; name: string; default_currency?: string } | undefined,
): InvoicingCompanyRecord {
    const linked = row.linkedStoreId?.trim();
    const resolved = linked ? storeLookup?.(linked) : undefined;
    const stores = resolved ? [resolved] : [];

    return {
        id: row.id,
        legal_name: row.legalName,
        trade_name: row.tradeName,
        jurisdiction: row.jurisdiction,
        default_currency: row.defaultCurrency,
        registration_number: row.registrationNumber,
        tax_id: row.taxId,
        vat_number: row.vatNumber,
        commercial_register: row.commercialRegister,
        register_court: row.registerCourt,
        register_number: row.registerNumber,
        managing_directors: row.managingDirectors,
        supervisory_board_chair: row.supervisoryBoardChair,
        street: row.street,
        city: row.city,
        postal_code: row.postalCode,
        state_region: row.stateRegion,
        country: normalizeIsoCountryCode(row.country),
        iban: row.iban,
        bic: row.bic,
        bank_name: row.bankName,
        bank_account: row.bankAccount,
        bank_code: row.bankCode,
        vat_payer: sqliteBoolToBoolean(row.vatPayer),
        vat_status: row.vatStatus,
        vat_rate_default: Number(row.vatRateDefault ?? 0) || 0,
        vat_turnover_limit: Number(row.vatTurnoverLimit ?? 0) || 0,
        legal_footer_note: row.legalFooterNote,
        issuer_name: row.issuerName,
        issuer_phone: row.issuerPhone,
        issuer_email: row.issuerEmail,
        website: row.website,
        invoice_number_prefix: row.invoiceNumberPrefix,
        stores,
        contacts: [],
        logo_url: row.logoDataUrl,
        signature_stamp_url: row.signatureDataUrl,
        app_settings: parseSettingsJson(row.appSettingsJson),
        email_settings: parseEmailSettingsJson(row.emailSettingsJson),
    };
}

export function companyHasBankAccount(row: EvoluCompanyRow): boolean {
    return Boolean(
        row.iban?.trim() ||
            row.bankAccount?.trim() ||
            row.bankName?.trim(),
    );
}

export function companyBankAccountLabel(row: EvoluCompanyRow): string {
    if (row.iban?.trim()) {
        const iban = row.iban.trim();
        if (iban.length > 8) {
            return `…${iban.slice(-4)}`;
        }
        return iban;
    }
    if (row.bankAccount?.trim()) {
        return row.bankAccount.trim();
    }
    return "";
}
