import { maxLength, NonEmptyString } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import type { EvoluExpenseRow } from "./expenseMap";
import type { CompanyId, ExpenseId, InvoicingLocalSchema } from "./schema";

const Opt16 = maxLength(16)(NonEmptyString);
const Opt32 = maxLength(32)(NonEmptyString);
const Opt64 = maxLength(64)(NonEmptyString);
const Opt255 = maxLength(255)(NonEmptyString);
const Opt4000 = maxLength(4000)(NonEmptyString);
const InternalNumberType = maxLength(64)(NonEmptyString);
const CurrencyType = maxLength(3)(NonEmptyString);

export type ExpenseSavePayload = {
    title?: string | null;
    external_number?: string | null;
    variable_symbol?: string | null;
    constant_symbol?: string | null;
    specific_symbol?: string | null;
    issue_date: string;
    delivery_date?: string | null;
    due_date?: string | null;
    total: number | string;
    currency?: string;
    internal_note?: string | null;
    mark_paid?: boolean;
};

function parseOpt<R>(value: string | null | undefined, type: { from: (value: string) => R }): R | { ok: true; value: null } {
    if (value == null || value.trim() === "") return { ok: true as const, value: null };
    return type.from(value.trim());
}

function formatTotal(value: number | string): string {
    const n = typeof value === "number" ? value : Number(value);
    return Number.isFinite(n) ? n.toFixed(2) : "0.00";
}

export function nextLocalExpenseInternalNumber(
    companyId: CompanyId,
    rows: EvoluExpenseRow[],
): string {
    const year = new Date().getFullYear();
    const prefix = `EXP${year}`;
    let max = 0;

    for (const row of rows) {
        if (row.companyId !== companyId || !row.internalNumber.startsWith(prefix)) continue;
        const suffix = row.internalNumber.slice(prefix.length);
        const n = parseInt(suffix, 10);
        if (!Number.isNaN(n)) max = Math.max(max, n);
    }

    return `${prefix}${String(max + 1).padStart(4, "0")}`;
}

function mapPayloadFields(payload: ExpenseSavePayload) {
    const title = parseOpt(payload.title ?? null, Opt255);
    if (!title.ok) return title;
    const external = parseOpt(payload.external_number ?? null, Opt64);
    if (!external.ok) return external;
    const vs = parseOpt(payload.variable_symbol ?? null, Opt32);
    if (!vs.ok) return vs;
    const ks = parseOpt(payload.constant_symbol ?? null, Opt16);
    if (!ks.ok) return ks;
    const ss = parseOpt(payload.specific_symbol ?? null, Opt16);
    if (!ss.ok) return ss;
    const issueDate = parseOpt(payload.issue_date, Opt32);
    if (!issueDate.ok || !issueDate.value) return { ok: false as const, error: "issue_date" };
    const deliveryDate = parseOpt(payload.delivery_date ?? payload.issue_date, Opt32);
    if (!deliveryDate.ok) return deliveryDate;
    const dueDate = parseOpt(payload.due_date ?? null, Opt32);
    if (!dueDate.ok) return dueDate;
    const currency = parseOpt(payload.currency || "EUR", CurrencyType);
    if (!currency.ok || !currency.value) return { ok: false as const, error: "currency" };
    const note = parseOpt(payload.internal_note ?? null, Opt4000);
    if (!note.ok) return note;

    return {
        ok: true as const,
        value: {
            title: title.value,
            externalNumber: external.value,
            variableSymbol: vs.value,
            constantSymbol: ks.value,
            specificSymbol: ss.value,
            issueDate: issueDate.value,
            deliveryDate: deliveryDate.value,
            dueDate: dueDate.value,
            total: formatTotal(payload.total),
            currency: currency.value,
            internalNote: note.value,
        },
    };
}

export function insertLocalExpense(
    evolu: Evolu<InvoicingLocalSchema>,
    companyId: CompanyId,
    payload: ExpenseSavePayload,
    existingRows: EvoluExpenseRow[],
    options?: { internalNumber?: string; id?: ExpenseId },
) {
    const mapped = mapPayloadFields(payload);
    if (!mapped.ok) return mapped;

    const numberSource = options?.internalNumber?.trim()
        || nextLocalExpenseInternalNumber(companyId, existingRows);
    const internalNumber = InternalNumberType.from(numberSource);
    if (!internalNumber.ok) return internalNumber;

    const markPaid = Boolean(payload.mark_paid);
    const now = new Date().toISOString();
    const row = {
        companyId,
        status: markPaid ? "paid" : "recorded",
        internalNumber: internalNumber.value,
        ...mapped.value,
        paidAt: markPaid ? now.slice(0, 10) : null,
        cancelledAt: null,
    };

    // A caller-provided id (stable id derived from a server inbox item) makes
    // the import idempotent across devices: the same item lands on the same
    // row everywhere instead of once per device.
    if (options?.id) {
        return evolu.upsert("expense", { id: options.id, ...row });
    }

    return evolu.insert("expense", row);
}

export function updateLocalExpense(
    evolu: Evolu<InvoicingLocalSchema>,
    expenseId: ExpenseId,
    payload: ExpenseSavePayload,
    current?: EvoluExpenseRow | null,
) {
    if (current?.status === "cancelled") {
        return { ok: false as const, error: "cancelled" };
    }

    const mapped = mapPayloadFields(payload);
    if (!mapped.ok) return mapped;

    return evolu.update("expense", {
        id: expenseId,
        ...mapped.value,
    });
}

export function markLocalExpensePaid(
    evolu: Evolu<InvoicingLocalSchema>,
    expenseId: ExpenseId,
) {
    const now = new Date().toISOString().slice(0, 10);
    return evolu.update("expense", {
        id: expenseId,
        status: "paid",
        paidAt: now,
    });
}

export function unmarkLocalExpensePaid(
    evolu: Evolu<InvoicingLocalSchema>,
    expenseId: ExpenseId,
) {
    return evolu.update("expense", {
        id: expenseId,
        status: "recorded",
        paidAt: null,
    });
}

export function cancelLocalExpense(
    evolu: Evolu<InvoicingLocalSchema>,
    expenseId: ExpenseId,
) {
    const now = new Date().toISOString();
    return evolu.update("expense", {
        id: expenseId,
        status: "cancelled",
        cancelledAt: now.slice(0, 10),
    });
}

export function duplicateLocalExpense(
    evolu: Evolu<InvoicingLocalSchema>,
    source: EvoluExpenseRow,
    existingRows: EvoluExpenseRow[],
) {
    const internalNumber = InternalNumberType.from(
        nextLocalExpenseInternalNumber(source.companyId, existingRows),
    );
    if (!internalNumber.ok) return internalNumber;

    const today = new Date().toISOString().slice(0, 10);

    return evolu.insert("expense", {
        companyId: source.companyId,
        status: "recorded",
        internalNumber: internalNumber.value,
        externalNumber: source.externalNumber,
        title: source.title,
        variableSymbol: source.variableSymbol,
        constantSymbol: source.constantSymbol,
        specificSymbol: source.specificSymbol,
        issueDate: today,
        deliveryDate: today,
        dueDate: source.dueDate,
        paidAt: null,
        cancelledAt: null,
        total: source.total,
        currency: source.currency,
        internalNote: source.internalNote,
    });
}
