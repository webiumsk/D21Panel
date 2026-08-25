import { maxLength, NonEmptyString, sqliteTrue } from "@evolu/common";
import type { Evolu } from "@evolu/common/local-first";
import type { ExpenseAttachmentId, ExpenseId, InvoicingLocalSchema } from "./schema";

const FilenameType = maxLength(255)(NonEmptyString);
const MimeType = maxLength(128)(NonEmptyString);
const SizeType = maxLength(32)(NonEmptyString);
const ContentType = maxLength(524288)(NonEmptyString);

/** ~384 KB decoded file (base64 payload limit in Evolu schema). */
export const LOCAL_EXPENSE_ATTACHMENT_MAX_BYTES = 384 * 1024;

export type EvoluExpenseAttachmentRow = {
    id: ExpenseAttachmentId;
    expenseId: ExpenseId;
    originalFilename: string | null;
    mimeType: string | null;
    sizeBytes: string | null;
    contentBase64: string | null;
};

export type ExpenseAttachmentApiRow = {
    id: string;
    original_filename: string;
    mime?: string | null;
    size_bytes?: number | null;
};

function arrayBufferToBase64(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = "";
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) {
        binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
    }
    return btoa(binary);
}

export async function readFileAsAttachmentBase64(file: File): Promise<string> {
    if (file.size > LOCAL_EXPENSE_ATTACHMENT_MAX_BYTES) {
        throw new Error("file_too_large");
    }
    return arrayBufferToBase64(await file.arrayBuffer());
}

export function attachmentRowToApi(row: EvoluExpenseAttachmentRow): ExpenseAttachmentApiRow {
    return {
        id: row.id,
        original_filename: row.originalFilename || "attachment",
        mime: row.mimeType,
        size_bytes: row.sizeBytes ? Number(row.sizeBytes) : null,
    };
}

export function attachmentContentBlob(row: EvoluExpenseAttachmentRow): Blob | null {
    if (!row.contentBase64) return null;
    const binary = atob(row.contentBase64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }
    return new Blob([bytes], { type: row.mimeType || "application/octet-stream" });
}

export function attachmentBlobUrl(row: EvoluExpenseAttachmentRow): string | null {
    const blob = attachmentContentBlob(row);
    if (!blob) return null;
    return URL.createObjectURL(blob);
}

export async function insertLocalExpenseAttachment(
    evolu: Evolu<InvoicingLocalSchema>,
    expenseId: ExpenseId,
    file: File,
) {
    const contentBase64 = await readFileAsAttachmentBase64(file);
    const originalFilename = FilenameType.from(file.name);
    if (!originalFilename.ok) return originalFilename;

    const mimeType = file.type
        ? MimeType.from(file.type)
        : { ok: true as const, value: null };
    if (!mimeType.ok) return mimeType;

    const sizeBytes = SizeType.from(String(file.size));
    if (!sizeBytes.ok) return sizeBytes;

    const encoded = ContentType.from(contentBase64);
    if (!encoded.ok) return encoded;

    return evolu.insert("expenseAttachment", {
        expenseId,
        originalFilename: originalFilename.value,
        mimeType: mimeType.value,
        sizeBytes: sizeBytes.value,
        contentBase64: encoded.value,
    });
}

/**
 * Attachment from already-encoded content (e.g. the UBL of a received
 * e-invoice handed over by the server inbox). Rejects payloads above the
 * schema cap instead of throwing, so callers can import without the file.
 */
export function insertLocalExpenseAttachmentFromBase64(
    evolu: Evolu<InvoicingLocalSchema>,
    expenseId: ExpenseId,
    input: { filename: string; mimeType: string | null; contentBase64: string },
    options?: { id?: ExpenseAttachmentId },
) {
    const originalFilename = FilenameType.from(input.filename);
    if (!originalFilename.ok) return originalFilename;

    const mimeType = input.mimeType
        ? MimeType.from(input.mimeType)
        : { ok: true as const, value: null };
    if (!mimeType.ok) return mimeType;

    const encoded = ContentType.from(input.contentBase64);
    if (!encoded.ok) return encoded;

    const sizeBytes = SizeType.from(String(base64DecodedLength(input.contentBase64)));
    if (!sizeBytes.ok) return sizeBytes;

    const row = {
        expenseId,
        originalFilename: originalFilename.value,
        mimeType: mimeType.value,
        sizeBytes: sizeBytes.value,
        contentBase64: encoded.value,
    };

    // A deterministic id (derived from the source inbox item) keeps repeated
    // imports on several devices on one attachment row.
    if (options?.id) {
        return evolu.upsert("expenseAttachment", { id: options.id, ...row });
    }

    return evolu.insert("expenseAttachment", row);
}

export function base64DecodedLength(base64: string): number {
    const padding = base64.endsWith("==") ? 2 : base64.endsWith("=") ? 1 : 0;
    return Math.max(0, Math.floor((base64.length * 3) / 4) - padding);
}

export function deleteLocalExpenseAttachment(
    evolu: Evolu<InvoicingLocalSchema>,
    attachmentId: ExpenseAttachmentId,
) {
    return evolu.update("expenseAttachment", { id: attachmentId, isDeleted: sqliteTrue });
}
