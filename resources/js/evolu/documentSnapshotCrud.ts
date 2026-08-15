import type { Evolu } from "@evolu/common/local-first";
import { sqliteTrue } from "@evolu/common";
import type { InvoicingLocalSchema, DocumentId } from "./schema";

/**
 * Issued document snapshots (audit F2). On issue - and on every later save of
 * an already issued document ("edit + re-freeze" interim model) - the full
 * renderable content (supplier, buyer, header, lines, totals) is frozen into
 * a documentSnapshot row. Rows are append-only: a re-freeze INSERTS a new
 * version, nothing ever updates or deletes an existing row. Issued documents
 * render from the newest snapshot, so editing a contact or company row can
 * no longer silently rewrite historical documents.
 *
 * All monetary values are stored as decimal STRINGS (never floats) and the
 * payload is serialized deterministically (sorted keys) so the same content
 * always produces byte-identical JSON.
 */

export const ISSUED_SNAPSHOT_FORMAT_VERSION = "1";

/** Frozen supplier identity - the fields PDF/e-mail/ISDOC/UBL rendering consumes. */
export type SnapshotCompanyV1 = {
    legal_name: string;
    trade_name: string | null;
    registration_number: string | null;
    tax_id: string | null;
    vat_number: string | null;
    street: string | null;
    city: string | null;
    postal_code: string | null;
    country: string | null;
    state_region: string | null;
    /** DE Geschaeftsbrief corporate footer data; older snapshots lack them. */
    register_court?: string | null;
    register_number?: string | null;
    managing_directors?: string | null;
    supervisory_board_chair?: string | null;
    iban: string | null;
    bic: string | null;
    bank_name: string | null;
    bank_account: string | null;
    bank_code: string | null;
    default_currency: string | null;
    jurisdiction: string | null;
    vat_payer: boolean | null;
    /** §7a is 'partial'; older snapshots may lack the field entirely. */
    vat_status?: 'none' | 'payer' | 'partial' | null;
    vat_rate_default: string | null;
    legal_footer_note: string | null;
    issuer_name: string | null;
    issuer_phone: string | null;
    issuer_email: string | null;
    website: string | null;
};

export type SnapshotContactV1 = {
    name: string;
    email: string | null;
    registration_number: string | null;
    tax_id: string | null;
    vat_id: string | null;
    street: string | null;
    city: string | null;
    postal_code: string | null;
    state_region: string | null;
    country: string | null;
};

export type SnapshotDocumentV1 = {
    type: string;
    title: string;
    number: string;
    variable_symbol: string | null;
    constant_symbol: string | null;
    specific_symbol: string | null;
    issue_date: string | null;
    delivery_date: string | null;
    due_date: string | null;
    currency: string;
    subtotal: string;
    tax_total: string;
    discount_percent: string;
    total: string;
    note_above_lines: string | null;
    note_footer: string | null;
    internal_note: string | null;
    pdf_locale: string | null;
    pdf_show_signature: boolean;
    pdf_show_payment_info: boolean;
    payment_bank_enabled: boolean;
    payment_btc_enabled: boolean;
};

export type SnapshotLineV1 = {
    name: string;
    description: string | null;
    quantity: string;
    unit: string | null;
    unit_price: string;
    line_discount_percent: string;
    tax_rate: string;
    line_total: string;
};

export type IssuedDocumentSnapshotV1 = {
    format_version: typeof ISSUED_SNAPSHOT_FORMAT_VERSION;
    company: SnapshotCompanyV1;
    contact: SnapshotContactV1 | null;
    document: SnapshotDocumentV1;
    lines: SnapshotLineV1[];
};

type ApiRecord = Record<string, unknown>;

function str(value: unknown): string | null {
    if (typeof value === "string" && value !== "") return value;
    if (typeof value === "number" && Number.isFinite(value)) return String(value);
    return null;
}

/** Decimal string for money/rate fields - numbers are stringified, never kept as floats. */
function dec(value: unknown, fallback = "0"): string {
    return str(value) ?? fallback;
}

function bool(value: unknown, fallback: boolean): boolean {
    return typeof value === "boolean" ? value : fallback;
}

function vatStatus(value: unknown): 'none' | 'payer' | 'partial' | null {
    return value === "none" || value === "payer" || value === "partial" ? value : null;
}

/**
 * Freezes API-shaped company/contact/document/line records (the shape
 * evoluCompanyToApi / evoluContactToApi / evoluDocumentToApi emit) into the
 * versioned snapshot content. Operational fields (status, payments, e-mail
 * settings, branding URLs, app settings) are deliberately NOT frozen - they
 * are merged live at render time.
 */
export function buildIssuedSnapshotContentV1(input: {
    company: ApiRecord;
    contact: ApiRecord | null;
    document: ApiRecord;
    lines: ApiRecord[];
}): { ok: true; value: IssuedDocumentSnapshotV1 } | { ok: false; error: string } {
    const legalName = str(input.company.legal_name);
    if (!legalName) return { ok: false, error: "snapshot_company_missing" };
    const number = str(input.document.number);
    if (!number) return { ok: false, error: "snapshot_number_missing" };
    const title = str(input.document.title);
    if (!title) return { ok: false, error: "snapshot_title_missing" };
    const type = str(input.document.type);
    if (!type) return { ok: false, error: "snapshot_type_missing" };
    if (!input.lines.length) return { ok: false, error: "snapshot_lines_missing" };

    const contactName = input.contact ? str(input.contact.name) : null;

    const value: IssuedDocumentSnapshotV1 = {
        format_version: ISSUED_SNAPSHOT_FORMAT_VERSION,
        company: {
            legal_name: legalName,
            trade_name: str(input.company.trade_name),
            registration_number: str(input.company.registration_number),
            tax_id: str(input.company.tax_id),
            vat_number: str(input.company.vat_number),
            street: str(input.company.street),
            city: str(input.company.city),
            postal_code: str(input.company.postal_code),
            country: str(input.company.country),
            state_region: str(input.company.state_region),
            register_court: str(input.company.register_court),
            register_number: str(input.company.register_number),
            managing_directors: str(input.company.managing_directors),
            supervisory_board_chair: str(input.company.supervisory_board_chair),
            iban: str(input.company.iban),
            bic: str(input.company.bic),
            bank_name: str(input.company.bank_name),
            bank_account: str(input.company.bank_account),
            bank_code: str(input.company.bank_code),
            default_currency: str(input.company.default_currency),
            jurisdiction: str(input.company.jurisdiction),
            vat_payer: typeof input.company.vat_payer === "boolean" ? input.company.vat_payer : null,
            vat_status: vatStatus(input.company.vat_status),
            vat_rate_default: str(input.company.vat_rate_default),
            legal_footer_note: str(input.company.legal_footer_note),
            issuer_name: str(input.company.issuer_name),
            issuer_phone: str(input.company.issuer_phone),
            issuer_email: str(input.company.issuer_email),
            website: str(input.company.website),
        },
        contact: contactName
            ? {
                  name: contactName,
                  email: str(input.contact?.email),
                  registration_number: str(input.contact?.registration_number),
                  tax_id: str(input.contact?.tax_id),
                  vat_id: str(input.contact?.vat_id),
                  street: str(input.contact?.street),
                  city: str(input.contact?.city),
                  postal_code: str(input.contact?.postal_code),
                  state_region: str(input.contact?.state_region),
                  country: str(input.contact?.country),
              }
            : null,
        document: {
            type,
            title,
            number,
            variable_symbol: str(input.document.variable_symbol),
            constant_symbol: str(input.document.constant_symbol),
            specific_symbol: str(input.document.specific_symbol),
            issue_date: str(input.document.issue_date),
            delivery_date: str(input.document.delivery_date),
            due_date: str(input.document.due_date),
            currency: str(input.document.currency) ?? "EUR",
            subtotal: dec(input.document.subtotal),
            tax_total: dec(input.document.tax_total),
            discount_percent: dec(input.document.discount_percent),
            total: dec(input.document.total),
            note_above_lines: str(input.document.note_above_lines),
            note_footer: str(input.document.note_footer),
            internal_note: str(input.document.internal_note),
            pdf_locale: str(input.document.pdf_locale),
            pdf_show_signature: bool(input.document.pdf_show_signature, true),
            pdf_show_payment_info: bool(input.document.pdf_show_payment_info, true),
            payment_bank_enabled: bool(input.document.payment_bank_enabled, true),
            payment_btc_enabled: bool(input.document.payment_btc_enabled, false),
        },
        lines: input.lines.map((line) => ({
            name: str(line.name) ?? "",
            description: str(line.description),
            quantity: dec(line.quantity),
            unit: str(line.unit),
            unit_price: dec(line.unit_price),
            line_discount_percent: dec(line.line_discount_percent),
            tax_rate: dec(line.tax_rate),
            line_total: dec(line.line_total),
        })),
    };

    if (value.lines.some((line) => line.name === "")) {
        return { ok: false, error: "snapshot_line_name_missing" };
    }

    return { ok: true, value };
}

/** JSON with recursively sorted object keys - same content, same bytes. */
export function deterministicStringify(value: unknown): string {
    return JSON.stringify(sortKeysDeep(value));
}

function sortKeysDeep(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(sortKeysDeep);
    }
    if (value !== null && typeof value === "object") {
        const out: Record<string, unknown> = {};
        for (const key of Object.keys(value as Record<string, unknown>).sort()) {
            out[key] = sortKeysDeep((value as Record<string, unknown>)[key]);
        }
        return out;
    }
    return value;
}

const DECIMAL_RE = /^-?\d+(\.\d+)?$/;

/**
 * Structural runtime validation of parsed snapshot content. Money and rate
 * fields must be decimal strings - a float smuggled in is a hard error.
 */
export function validateIssuedSnapshotV1(
    value: unknown,
): { ok: true; value: IssuedDocumentSnapshotV1 } | { ok: false; error: string } {
    const root = value as Partial<IssuedDocumentSnapshotV1> | null;
    if (!root || typeof root !== "object") return { ok: false, error: "snapshot_invalid" };
    if (root.format_version !== ISSUED_SNAPSHOT_FORMAT_VERSION) {
        return { ok: false, error: "snapshot_version_unsupported" };
    }
    const company = root.company;
    if (!company || typeof company !== "object" || typeof company.legal_name !== "string" || !company.legal_name) {
        return { ok: false, error: "snapshot_company_invalid" };
    }
    if (company.vat_status != null && vatStatus(company.vat_status) === null) {
        return { ok: false, error: "snapshot_company_invalid" };
    }
    const doc = root.document;
    if (
        !doc
        || typeof doc !== "object"
        || typeof doc.number !== "string"
        || !doc.number
        || typeof doc.title !== "string"
        || typeof doc.type !== "string"
        || typeof doc.currency !== "string"
    ) {
        return { ok: false, error: "snapshot_document_invalid" };
    }
    for (const field of ["subtotal", "tax_total", "discount_percent", "total"] as const) {
        if (typeof doc[field] !== "string" || !DECIMAL_RE.test(doc[field] as string)) {
            return { ok: false, error: "snapshot_money_not_decimal_string" };
        }
    }
    if (root.contact != null && (typeof root.contact !== "object" || typeof root.contact.name !== "string")) {
        return { ok: false, error: "snapshot_contact_invalid" };
    }
    if (!Array.isArray(root.lines) || root.lines.length === 0) {
        return { ok: false, error: "snapshot_lines_invalid" };
    }
    for (const line of root.lines) {
        if (!line || typeof line !== "object" || typeof line.name !== "string" || !line.name) {
            return { ok: false, error: "snapshot_lines_invalid" };
        }
        for (const field of ["quantity", "unit_price", "line_discount_percent", "tax_rate", "line_total"] as const) {
            if (typeof line[field] !== "string" || !DECIMAL_RE.test(line[field] as string)) {
                return { ok: false, error: "snapshot_money_not_decimal_string" };
            }
        }
    }
    return { ok: true, value: root as IssuedDocumentSnapshotV1 };
}

export type EvoluDocumentSnapshotRow = {
    id: string;
    documentId: string;
    formatVersion: string;
    payloadJson: string;
    backfilled: number | null;
    createdAt: string;
};

/** Newest snapshot version for a document (rows arrive ordered by createdAt). */
export function latestSnapshotRowForDocument(
    rows: readonly EvoluDocumentSnapshotRow[],
    documentId: string,
): EvoluDocumentSnapshotRow | null {
    for (let i = rows.length - 1; i >= 0; i -= 1) {
        if (rows[i].documentId === documentId) return rows[i];
    }
    return null;
}

export function parseIssuedSnapshotRow(
    row: EvoluDocumentSnapshotRow,
): { ok: true; value: IssuedDocumentSnapshotV1; backfilled: boolean } | { ok: false; error: string } {
    if (row.formatVersion !== ISSUED_SNAPSHOT_FORMAT_VERSION) {
        return { ok: false, error: "snapshot_version_unsupported" };
    }
    let parsed: unknown;
    try {
        parsed = JSON.parse(row.payloadJson);
    } catch {
        return { ok: false, error: "snapshot_json_invalid" };
    }
    const validated = validateIssuedSnapshotV1(parsed);
    if (!validated.ok) return validated;
    return { ok: true, value: validated.value, backfilled: row.backfilled === 1 };
}

/**
 * Validates and appends a snapshot version. Never updates an existing row.
 * Returns the insert result so issue flows can refuse to flip a document to
 * issued when the snapshot could not be persisted; the serialized payload is
 * returned so callers can hash it (allocator confirm commitment, audit F3).
 */
export function insertIssuedDocumentSnapshot(
    evolu: Evolu<InvoicingLocalSchema>,
    documentId: DocumentId,
    content: IssuedDocumentSnapshotV1,
    options: { backfilled?: boolean } = {},
): { ok: true; payloadJson: string } | { ok: false; error: string } {
    const validated = validateIssuedSnapshotV1(content);
    if (!validated.ok) return validated;

    const payloadJson = deterministicStringify(validated.value);
    const result = evolu.insert("documentSnapshot", {
        documentId,
        formatVersion: ISSUED_SNAPSHOT_FORMAT_VERSION,
        payloadJson,
        backfilled: options.backfilled ? sqliteTrue : null,
    });
    if (!result.ok) {
        return { ok: false, error: "snapshot_persist_failed" };
    }
    return { ok: true, payloadJson };
}
