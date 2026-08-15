/**
 * DE invoice-content rules (operator-supplied, 2026-07-22).
 *
 * § 33 UStDV: invoices up to 250 EUR gross (Kleinbetragsrechnung) may omit
 * the buyer's identity. Above 250 EUR, § 14 UStG requires the buyer's full
 * name and address on the invoice. The threshold is defined in EUR, so the
 * guard only applies to EUR-denominated documents of German companies.
 */
export const DE_KLEINBETRAG_LIMIT_EUR = 250;

export type DeInvoiceBuyer = {
  name?: string | null;
  street?: string | null;
  city?: string | null;
  postal_code?: string | null;
} | null;

function filled(value: string | null | undefined): boolean {
  return (value || '').trim() !== '';
}

/**
 * True when issuing would violate the DE full-invoice rule: a German
 * company's EUR invoice above the Kleinbetrag limit without the buyer's
 * complete name and address. Small-amount invoices and other currencies
 * pass freely. § 14 UStG governs invoices - quotes, proformas and credit
 * notes are never blocked (credit notes inherit the buyer from their
 * source invoice anyway).
 */
export function deFullInvoiceBuyerMissing(input: {
  documentType: string | null | undefined;
  jurisdiction: string | null | undefined;
  currency: string | null | undefined;
  totalGross: number;
  buyer: DeInvoiceBuyer;
}): boolean {
  if (input.documentType !== 'invoice') {
    return false;
  }
  if (input.jurisdiction !== 'eu_de') {
    return false;
  }
  if ((input.currency || '').trim().toUpperCase() !== 'EUR') {
    return false;
  }
  if (!(input.totalGross > DE_KLEINBETRAG_LIMIT_EUR)) {
    return false;
  }

  const buyer = input.buyer;
  return !(
    buyer
    && filled(buyer.name)
    && filled(buyer.street)
    && filled(buyer.city)
    && filled(buyer.postal_code)
  );
}
