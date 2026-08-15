import type { EvoluCompanyRow } from "./companyMap";
import type { EvoluContactRow } from "./contactMap";
import type { DocumentLinePayload } from "./documentCrud";
import {
    useCompanyVatPolicy,
    type VatPolicyCompany,
    type VatPolicyContact,
} from "@/composables/useCompanyVatPolicy";

function policyCompany(company: EvoluCompanyRow): VatPolicyCompany {
    return {
        country: company.country,
        jurisdiction: company.jurisdiction,
        vat_payer: company.vatPayer === 1,
        vat_status: company.vatStatus,
        vat_rate_default: company.vatRateDefault,
    };
}

function policyContact(contact: EvoluContactRow | null): VatPolicyContact {
    return contact ? { country: contact.country, vat_id: contact.vatId } : null;
}

/**
 * Tax options resolved through the shared VAT policy, contact-aware: the
 * generated recurring invoice charges no VAT for non-payers, §7a companies
 * and §4/EU-B2B reverse-charge counterparties - same rules as the manual
 * invoice form. Kept free of the Evolu client so it stays unit-testable.
 */
export function taxOptionsForProfile(company: EvoluCompanyRow, contact: EvoluContactRow | null) {
    const vatPolicy = useCompanyVatPolicy();
    const forCompany = policyCompany(company);
    const forContact = policyContact(contact);
    const defaultVat = Number(company.vatRateDefault ?? 23);
    return {
        defaultVat,
        lineTaxApplies: () => vatPolicy.calculatesVatAmounts(forCompany, forContact),
        lineTaxRate: (line: DocumentLinePayload) => {
            // Number(null) is 0, not NaN - an unset line rate must fall
            // back to the company default, never to 0% VAT.
            const raw = line.tax_rate;
            const rate = raw === null || raw === undefined ? Number.NaN : Number(raw);
            return vatPolicy.resolveLineTaxRate(
                forCompany,
                forContact,
                Number.isFinite(rate) ? rate : null,
            );
        },
    };
}
