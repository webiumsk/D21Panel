import { booleanToSqliteBoolean } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import type { CompanyJurisdictionValue } from "@/config/companyJurisdiction";
import {
    CountryType,
    CurrencyType,
    LegalNameType,
    Opt16,
    Opt32,
    Opt64,
    Opt128,
    Opt255,
    Opt512,
    parseOptional,
} from "./parseUtils";
import type { CompanyId, InvoicingLocalSchema } from "./schema";

export type LocalCompanyContactPatch = {
    legal_name?: string;
    street?: string | null;
    city?: string | null;
    postal_code?: string | null;
    state_region?: string | null;
    country?: string | null;
    jurisdiction?: CompanyJurisdictionValue;
    registration_number?: string | null;
    tax_id?: string | null;
    vat_number?: string | null;
    commercial_register?: string | null;
    register_court?: string | null;
    register_number?: string | null;
    managing_directors?: string | null;
    supervisory_board_chair?: string | null;
    issuer_name?: string | null;
    issuer_phone?: string | null;
    issuer_email?: string | null;
    website?: string | null;
    legal_footer_note?: string | null;
    vat_status?: "none" | "payer" | "partial";
    vat_payer?: boolean;
    vat_rate_default?: number;
    vat_turnover_limit?: number;
    linked_store_id?: string | null;
};

export type LocalCompanyBankPatch = {
    bank_name?: string | null;
    bank_account?: string | null;
    bank_code?: string | null;
    iban?: string | null;
    bic?: string | null;
    default_currency?: string;
};

export function updateLocalCompanyContact(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: CompanyId,
    patch: LocalCompanyContactPatch,
) {
    const update: Record<string, unknown> = { id: companyId };

    if (patch.legal_name != null) {
        const legalName = LegalNameType.from(patch.legal_name.trim());
        if (!legalName.ok) return legalName;
        update.legalName = legalName.value;
    }
    if (patch.jurisdiction != null) update.jurisdiction = patch.jurisdiction;
    if (patch.street !== undefined) {
        const street = parseOptional(patch.street, Opt255);
        if (!street.ok) return street;
        update.street = street.value;
    }
    if (patch.city !== undefined) {
        const city = parseOptional(patch.city, Opt128);
        if (!city.ok) return city;
        update.city = city.value;
    }
    if (patch.postal_code !== undefined) {
        const postal = parseOptional(patch.postal_code, Opt32);
        if (!postal.ok) return postal;
        update.postalCode = postal.value;
    }
    if (patch.state_region !== undefined) {
        const state = parseOptional(patch.state_region, Opt64);
        if (!state.ok) return state;
        update.stateRegion = state.value;
    }
    if (patch.country !== undefined) {
        const country = parseOptional(patch.country, CountryType);
        if (!country.ok) return country;
        update.country = country.value;
    }
    if (patch.registration_number !== undefined) {
        const reg = parseOptional(patch.registration_number, Opt64);
        if (!reg.ok) return reg;
        update.registrationNumber = reg.value;
    }
    if (patch.tax_id !== undefined) {
        const tax = parseOptional(patch.tax_id, Opt64);
        if (!tax.ok) return tax;
        update.taxId = tax.value;
    }
    if (patch.vat_number !== undefined) {
        const vat = parseOptional(patch.vat_number, Opt32);
        if (!vat.ok) return vat;
        update.vatNumber = vat.value;
    }
    if (patch.commercial_register !== undefined) {
        const cr = parseOptional(patch.commercial_register, Opt512);
        if (!cr.ok) return cr;
        update.commercialRegister = cr.value;
    }
    if (patch.register_court !== undefined) {
        const v = parseOptional(patch.register_court, Opt128);
        if (!v.ok) return v;
        update.registerCourt = v.value;
    }
    if (patch.register_number !== undefined) {
        const v = parseOptional(patch.register_number, Opt64);
        if (!v.ok) return v;
        update.registerNumber = v.value;
    }
    if (patch.managing_directors !== undefined) {
        const v = parseOptional(patch.managing_directors, Opt512);
        if (!v.ok) return v;
        update.managingDirectors = v.value;
    }
    if (patch.supervisory_board_chair !== undefined) {
        const v = parseOptional(patch.supervisory_board_chair, Opt128);
        if (!v.ok) return v;
        update.supervisoryBoardChair = v.value;
    }
    if (patch.issuer_name !== undefined) {
        const v = parseOptional(patch.issuer_name, Opt255);
        if (!v.ok) return v;
        update.issuerName = v.value;
    }
    if (patch.issuer_phone !== undefined) {
        const v = parseOptional(patch.issuer_phone, Opt64);
        if (!v.ok) return v;
        update.issuerPhone = v.value;
    }
    if (patch.issuer_email !== undefined) {
        const v = parseOptional(patch.issuer_email, Opt255);
        if (!v.ok) return v;
        update.issuerEmail = v.value;
    }
    if (patch.website !== undefined) {
        const v = parseOptional(patch.website, Opt255);
        if (!v.ok) return v;
        update.website = v.value;
    }
    if (patch.legal_footer_note !== undefined) {
        const v = parseOptional(patch.legal_footer_note, Opt512);
        if (!v.ok) return v;
        update.legalFooterNote = v.value;
    }
    if (patch.vat_status != null) {
        update.vatStatus = patch.vat_status;
        update.vatPayer = booleanToSqliteBoolean(
            patch.vat_status === "payer" || patch.vat_status === "partial",
        );
    } else if (patch.vat_payer != null) {
        update.vatPayer = booleanToSqliteBoolean(patch.vat_payer);
        update.vatStatus = patch.vat_payer ? "payer" : "none";
    }
    if (patch.vat_rate_default != null) {
        const rate = String(patch.vat_rate_default);
        const parsed = Opt16.from(rate);
        if (!parsed.ok) return parsed;
        update.vatRateDefault = parsed.value;
    }
    if (patch.vat_turnover_limit != null) {
        const limit = patch.vat_turnover_limit > 0 ? String(patch.vat_turnover_limit) : null;
        if (limit === null) {
            update.vatTurnoverLimit = null;
        } else {
            const parsed = Opt16.from(limit);
            if (!parsed.ok) return parsed;
            update.vatTurnoverLimit = parsed.value;
        }
    }
    if (patch.linked_store_id !== undefined) {
        const store = parseOptional(patch.linked_store_id, Opt64);
        if (!store.ok) return store;
        update.linkedStoreId = store.value;
    }

    return evolu.update("company", update as Parameters<typeof evolu.update<"company">>[1]);
}

export function updateLocalCompanyBank(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: CompanyId,
    patch: LocalCompanyBankPatch,
) {
    const update: Record<string, unknown> = { id: companyId };

    if (patch.bank_name !== undefined) {
        const v = parseOptional(patch.bank_name, Opt128);
        if (!v.ok) return v;
        update.bankName = v.value;
    }
    if (patch.bank_account !== undefined) {
        const v = parseOptional(patch.bank_account, Opt64);
        if (!v.ok) return v;
        update.bankAccount = v.value;
    }
    if (patch.bank_code !== undefined) {
        const v = parseOptional(patch.bank_code, Opt16);
        if (!v.ok) return v;
        update.bankCode = v.value;
    }
    if (patch.iban !== undefined) {
        const v = parseOptional(patch.iban, Opt64);
        if (!v.ok) return v;
        update.iban = v.value;
    }
    if (patch.bic !== undefined) {
        const v = parseOptional(patch.bic, Opt16);
        if (!v.ok) return v;
        update.bic = v.value;
    }
    if (patch.default_currency != null) {
        const currency = CurrencyType.from(patch.default_currency.trim());
        if (!currency.ok) return currency;
        update.defaultCurrency = currency.value;
    }

    return evolu.update("company", update as Parameters<typeof evolu.update<"company">>[1]);
}
