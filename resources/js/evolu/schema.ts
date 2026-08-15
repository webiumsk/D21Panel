import {
    id,
    maxLength,
    NonEmptyString,
    nullOr,
    SqliteBoolean,
    union,
    type EvoluSchema,
} from "@evolu/common";

export const CompanyId = id("Company");
export type CompanyId = typeof CompanyId.Type;

export const ContactId = id("Contact");
export type ContactId = typeof ContactId.Type;

export const DocumentId = id("Document");
export type DocumentId = typeof DocumentId.Type;

export const DocumentLineId = id("DocumentLine");
export type DocumentLineId = typeof DocumentLineId.Type;

export const NumberSeriesId = id("NumberSeries");
export type NumberSeriesId = typeof NumberSeriesId.Type;

export const DocumentEventId = id("DocumentEvent");
export type DocumentEventId = typeof DocumentEventId.Type;

export const ExpenseId = id("Expense");
export type ExpenseId = typeof ExpenseId.Type;

export const ExpenseAttachmentId = id("ExpenseAttachment");
export type ExpenseAttachmentId = typeof ExpenseAttachmentId.Type;

export const RecurringProfileId = id("RecurringProfile");
export type RecurringProfileId = typeof RecurringProfileId.Type;

export const RecurringProfileLineId = id("RecurringProfileLine");
export type RecurringProfileLineId = typeof RecurringProfileLineId.Type;

export const WarehouseId = id("Warehouse");
export type WarehouseId = typeof WarehouseId.Type;

export const StockItemId = id("StockItem");
export type StockItemId = typeof StockItemId.Type;

export const StockBalanceId = id("StockBalance");
export type StockBalanceId = typeof StockBalanceId.Type;

export const StockMovementId = id("StockMovement");
export type StockMovementId = typeof StockMovementId.Type;

export const BankImportBatchId = id("BankImportBatch");
export type BankImportBatchId = typeof BankImportBatchId.Type;

export const BankTransactionId = id("BankTransaction");
export type BankTransactionId = typeof BankTransactionId.Type;

export const BankTransactionMatchId = id("BankTransactionMatch");
export type BankTransactionMatchId = typeof BankTransactionMatchId.Type;

export const DocumentSnapshotId = id("DocumentSnapshot");
export type DocumentSnapshotId = typeof DocumentSnapshotId.Type;

export const InvoiceTemplateId = id("InvoiceTemplate");
export type InvoiceTemplateId = typeof InvoiceTemplateId.Type;

const LegalName = maxLength(255)(NonEmptyString);
const OptionalString16 = nullOr(maxLength(16)(NonEmptyString));
const OptionalString32 = nullOr(maxLength(32)(NonEmptyString));
const OptionalString64 = nullOr(maxLength(64)(NonEmptyString));
const OptionalString128 = nullOr(maxLength(128)(NonEmptyString));
const OptionalString255 = nullOr(maxLength(255)(NonEmptyString));
const OptionalString512 = nullOr(maxLength(512)(NonEmptyString));
const OptionalString1000 = nullOr(maxLength(1000)(NonEmptyString));
const OptionalString4000 = nullOr(maxLength(4000)(NonEmptyString));
/** Compressed image data URLs (logo / signature stamp), max ~128 KB each. */
const OptionalImageDataUrl = nullOr(maxLength(131072)(NonEmptyString));
/** Raw base64 file payload (~384 KB decoded max). Synced via E2EE relay. */
const OptionalAttachmentContent = nullOr(maxLength(524288)(NonEmptyString));
/** Issued document snapshot JSON (frozen supplier/buyer/lines/totals). */
const SnapshotPayloadJson = maxLength(262144)(NonEmptyString);
const SnapshotFormatVersion = maxLength(16)(NonEmptyString);
const CountryCode = maxLength(2)(NonEmptyString);
const CurrencyCode = maxLength(3)(NonEmptyString);
const ContactName = maxLength(255)(NonEmptyString);
const ContactEmail = nullOr(maxLength(100)(NonEmptyString));
const LineName = maxLength(255)(NonEmptyString);
const DocumentTitle = maxLength(1000)(NonEmptyString);
const NumberSeriesName = maxLength(255)(NonEmptyString);
const NumberSeriesFormat = maxLength(64)(NonEmptyString);
const DocumentAction = maxLength(128)(NonEmptyString);

export const CompanyJurisdiction = union(
    "eu_sk",
    "eu_cz",
    "eu_de",
    "eu_at",
    "eu_other",
    "ch",
    "us",
    "uk",
    "offshore",
    "asia",
);
export type CompanyJurisdiction = typeof CompanyJurisdiction.Type;

export const VatStatus = union("none", "payer", "partial");
export type VatStatus = typeof VatStatus.Type;

export const DocumentStatus = union("draft", "issued", "paid", "cancelled");
export type DocumentStatus = typeof DocumentStatus.Type;

export const ExpenseStatus = union("recorded", "paid", "cancelled");
export type ExpenseStatus = typeof ExpenseStatus.Type;

export const QuoteStatus = union("pending", "approved", "rejected", "expired");
export type QuoteStatus = typeof QuoteStatus.Type;

export const DocumentType = union(
    "invoice",
    "proforma",
    "quote",
    "credit_note",
    "delivery_note",
    "order_received",
    "order_issued",
    "expense",
);
export type DocumentType = typeof DocumentType.Type;

export const ResetPeriod = union("yearly", "monthly", "never");
export type ResetPeriod = typeof ResetPeriod.Type;

export const RecurringInterval = union("monthly", "yearly");
export type RecurringInterval = typeof RecurringInterval.Type;

export const RecurringDocType = union("invoice", "proforma");
export type RecurringDocType = typeof RecurringDocType.Type;

export const RecurringDeliveryDateMode = union("on_issue", "empty");
export type RecurringDeliveryDateMode = typeof RecurringDeliveryDateMode.Type;

export const WarehouseType = union("own", "owned_external", "supplier_availability");
export type WarehouseType = typeof WarehouseType.Type;

export const StockMovementSource = union(
    "manual",
    "import",
    "document_issue",
    "document_cancel",
    "document_adjustment",
    "transfer",
    "purchase_receipt",
);
export type StockMovementSource = typeof StockMovementSource.Type;

export const BankTransactionDirection = union("credit", "debit");
export type BankTransactionDirection = typeof BankTransactionDirection.Type;

export const BankTransactionMatchStatus = union("unmatched", "matched", "ignored");
export type BankTransactionMatchStatus = typeof BankTransactionMatchStatus.Type;

export const BankImportSource = union("csv", "camt053", "inbound_email", "manual", "wise");
export type BankImportSource = typeof BankImportSource.Type;

export const BankMatchType = union("auto", "manual");
export type BankMatchType = typeof BankMatchType.Type;

/** Local-first invoicing schema. Data lives in browser SQLite, synced E2EE via relay. */
export const InvoicingLocalSchema = {
    company: {
        id: CompanyId,
        legalName: LegalName,
        tradeName: OptionalString255,
        jurisdiction: CompanyJurisdiction,
        defaultCurrency: CurrencyCode,
        registrationNumber: OptionalString64,
        taxId: OptionalString64,
        vatNumber: OptionalString32,
        commercialRegister: OptionalString512,
        // DE Geschaeftsbrief corporate data (GmbH/UG mandatory footer).
        registerCourt: OptionalString128,
        registerNumber: OptionalString64,
        managingDirectors: OptionalString512,
        supervisoryBoardChair: OptionalString128,
        street: OptionalString255,
        city: OptionalString128,
        postalCode: OptionalString32,
        country: nullOr(CountryCode),
        stateRegion: OptionalString64,
        iban: OptionalString64,
        bic: OptionalString16,
        bankName: OptionalString128,
        bankAccount: OptionalString64,
        bankCode: OptionalString16,
        vatPayer: nullOr(SqliteBoolean),
        vatStatus: VatStatus,
        vatRateDefault: OptionalString16,
        /** Annual turnover limit for VAT registration (numeric, stored as string). */
        vatTurnoverLimit: OptionalString16,
        legalFooterNote: OptionalString512,
        issuerName: OptionalString255,
        issuerPhone: OptionalString64,
        issuerEmail: OptionalString255,
        website: OptionalString255,
        invoiceNumberPrefix: OptionalString32,
        /** Satflux store UUID (BTCPay link stored locally until server bridge). */
        linkedStoreId: OptionalString64,
        /** JSON blob for app settings (rounding, PDF patterns, display flags). */
        appSettingsJson: OptionalString4000,
        /** JSON blob for email delivery + templates (+ SMTP credentials locally). */
        emailSettingsJson: OptionalString4000,
        /** Data URL for company logo (JPEG/WebP, resized locally). */
        logoDataUrl: OptionalImageDataUrl,
        /** Data URL for signature stamp image (JPEG/WebP, resized locally). */
        signatureDataUrl: OptionalImageDataUrl,
    },
    contact: {
        id: ContactId,
        companyId: CompanyId,
        name: ContactName,
        registrationNumber: OptionalString64,
        peppolParticipantId: OptionalString64,
        email: ContactEmail,
        phone: OptionalString64,
        fax: OptionalString64,
        taxId: OptionalString64,
        vatId: OptionalString32,
        street: OptionalString255,
        city: OptionalString128,
        postalCode: OptionalString32,
        stateRegion: OptionalString64,
        country: OptionalString128,
        bankAccount: OptionalString64,
        bankCode: OptionalString16,
        iban: OptionalString64,
        swift: OptionalString16,
        deliveryStreet: OptionalString255,
        deliveryPostalCode: OptionalString32,
        deliveryCity: OptionalString128,
        deliveryCountry: OptionalString128,
        defaultPaymentTermsDays: OptionalString16,
        notes: OptionalString1000,
        contactPersonsJson: OptionalString4000,
        isActive: nullOr(SqliteBoolean),
    },
    document: {
        id: DocumentId,
        companyId: CompanyId,
        contactId: nullOr(ContactId),
        documentType: DocumentType,
        status: DocumentStatus,
        quoteStatus: nullOr(QuoteStatus),
        title: DocumentTitle,
        number: OptionalString64,
        sourceDocumentId: nullOr(DocumentId),
        issueDate: OptionalString32,
        deliveryDate: OptionalString32,
        dueDate: OptionalString32,
        variableSymbol: OptionalString32,
        constantSymbol: OptionalString16,
        specificSymbol: OptionalString16,
        currency: nullOr(CurrencyCode),
        subtotal: OptionalString32,
        taxTotal: OptionalString32,
        discountPercent: OptionalString16,
        total: OptionalString32,
        noteAboveLines: OptionalString4000,
        noteFooter: OptionalString4000,
        internalNote: OptionalString4000,
        pdfLocale: OptionalString16,
        pdfBankQr: OptionalString16,
        pdfShowSignature: nullOr(SqliteBoolean),
        pdfShowPaymentInfo: nullOr(SqliteBoolean),
        paymentBankEnabled: nullOr(SqliteBoolean),
        paymentBtcEnabled: nullOr(SqliteBoolean),
        storeId: OptionalString64,
        tagsJson: OptionalString1000,
        paidAt: OptionalString32,
        amountPaid: OptionalString32,
        emailSentAt: OptionalString32,
    },
    documentLine: {
        id: DocumentLineId,
        documentId: DocumentId,
        sortOrder: OptionalString16,
        name: LineName,
        description: OptionalString4000,
        quantity: OptionalString32,
        unit: OptionalString32,
        unitPrice: OptionalString32,
        lineDiscountPercent: OptionalString16,
        taxRate: OptionalString16,
        lineTotal: OptionalString32,
        companyStockItemId: OptionalString64,
        companyWarehouseId: OptionalString64,
    },
    numberSeries: {
        id: NumberSeriesId,
        companyId: CompanyId,
        name: NumberSeriesName,
        documentType: DocumentType,
        format: NumberSeriesFormat,
        resetPeriod: ResetPeriod,
        isDefault: nullOr(SqliteBoolean),
        periodKey: OptionalString32,
        lastNumber: OptionalString16,
    },
    documentEvent: {
        id: DocumentEventId,
        documentId: DocumentId,
        action: DocumentAction,
        metadataJson: OptionalString4000,
    },
    /**
     * Frozen copy of an issued document (F2): supplier, buyer, lines and
     * totals at issue time. Append-only by convention - every re-freeze
     * (edit of an issued document) INSERTS a new row; rows are never
     * updated. Render of issued documents reads the newest row.
     */
    documentSnapshot: {
        id: DocumentSnapshotId,
        documentId: DocumentId,
        formatVersion: SnapshotFormatVersion,
        payloadJson: SnapshotPayloadJson,
        /** Set on snapshots reconstructed for documents issued before F2 - lower fidelity. */
        backfilled: nullOr(SqliteBoolean),
    },
    /** Reusable invoice template: a saved document payload (header + lines, no
     * number/dates) applied to prefill a new draft. */
    invoiceTemplate: {
        id: InvoiceTemplateId,
        companyId: CompanyId,
        name: LineName,
        payloadJson: SnapshotPayloadJson,
    },
    expense: {
        id: ExpenseId,
        companyId: CompanyId,
        status: ExpenseStatus,
        internalNumber: maxLength(64)(NonEmptyString),
        externalNumber: OptionalString64,
        title: OptionalString255,
        variableSymbol: OptionalString32,
        constantSymbol: OptionalString16,
        specificSymbol: OptionalString16,
        issueDate: OptionalString32,
        deliveryDate: OptionalString32,
        dueDate: OptionalString32,
        paidAt: OptionalString32,
        cancelledAt: OptionalString32,
        total: OptionalString32,
        currency: nullOr(CurrencyCode),
        internalNote: OptionalString4000,
    },
    expenseAttachment: {
        id: ExpenseAttachmentId,
        expenseId: ExpenseId,
        originalFilename: OptionalString255,
        mimeType: OptionalString128,
        sizeBytes: OptionalString32,
        contentBase64: OptionalAttachmentContent,
    },
    recurringProfile: {
        id: RecurringProfileId,
        companyId: CompanyId,
        contactId: nullOr(ContactId),
        storeId: OptionalString64,
        documentType: RecurringDocType,
        isActive: nullOr(SqliteBoolean),
        recurrenceInterval: RecurringInterval,
        firstIssueDate: OptionalString32,
        nextIssueDate: OptionalString32,
        endsAt: OptionalString32,
        repeatIndefinitely: nullOr(SqliteBoolean),
        issueLastDayOfMonth: nullOr(SqliteBoolean),
        title: OptionalString1000,
        variableSymbol: OptionalString32,
        constantSymbol: OptionalString16,
        specificSymbol: OptionalString16,
        paymentTermsDays: OptionalString16,
        deliveryDateMode: RecurringDeliveryDateMode,
        currency: nullOr(CurrencyCode),
        discountPercent: OptionalString16,
        subtotal: OptionalString32,
        taxTotal: OptionalString32,
        total: OptionalString32,
        noteAboveLines: OptionalString4000,
        noteFooter: OptionalString4000,
        internalNote: OptionalString4000,
        pdfLocale: OptionalString16,
        pdfBankQr: OptionalString16,
        pdfShowSignature: nullOr(SqliteBoolean),
        pdfShowPaymentInfo: nullOr(SqliteBoolean),
        paymentBtcEnabled: nullOr(SqliteBoolean),
        paymentBankEnabled: nullOr(SqliteBoolean),
        sendEmailAfterIssue: nullOr(SqliteBoolean),
        emailBcc: OptionalString255,
        tagsJson: OptionalString1000,
        lastGeneratedDocumentId: nullOr(DocumentId),
        lastGeneratedAt: OptionalString32,
    },
    recurringProfileLine: {
        id: RecurringProfileLineId,
        recurringProfileId: RecurringProfileId,
        sortOrder: OptionalString16,
        name: LineName,
        description: OptionalString4000,
        quantity: OptionalString32,
        unit: OptionalString32,
        unitPrice: OptionalString32,
        lineDiscountPercent: OptionalString16,
        taxRate: OptionalString16,
        lineTotal: OptionalString32,
    },
    companyWarehouse: {
        id: WarehouseId,
        companyId: CompanyId,
        name: maxLength(255)(NonEmptyString),
        type: WarehouseType,
        deductOnIssue: nullOr(SqliteBoolean),
        isDefault: nullOr(SqliteBoolean),
        isActive: nullOr(SqliteBoolean),
        companyContactId: nullOr(ContactId),
        street: OptionalString255,
        city: OptionalString128,
        postalCode: OptionalString32,
        country: nullOr(CountryCode),
        notes: OptionalString4000,
    },
    companyStockItem: {
        id: StockItemId,
        companyId: CompanyId,
        name: LineName,
        sku: OptionalString64,
        description: OptionalString4000,
        unit: OptionalString32,
        trackInventory: nullOr(SqliteBoolean),
        purchaseUnitPrice: OptionalString32,
        purchaseCurrency: nullOr(CurrencyCode),
        saleUnitPrice: OptionalString32,
        internalNote: OptionalString4000,
        excludeFromSuggester: nullOr(SqliteBoolean),
    },
    companyStockBalance: {
        id: StockBalanceId,
        companyId: CompanyId,
        companyWarehouseId: WarehouseId,
        companyStockItemId: StockItemId,
        quantityOnHand: OptionalString32,
    },
    companyStockMovement: {
        id: StockMovementId,
        companyId: CompanyId,
        companyStockItemId: StockItemId,
        companyWarehouseId: WarehouseId,
        quantityAfter: OptionalString32,
        quantityDelta: OptionalString32,
        purchaseUnitPrice: OptionalString32,
        saleUnitPrice: OptionalString32,
        note: OptionalString4000,
        source: StockMovementSource,
        businessDocumentId: nullOr(DocumentId),
        documentNumber: OptionalString64,
        documentType: OptionalString32,
        movementAt: OptionalString32,
    },
    bankImportBatch: {
        id: BankImportBatchId,
        companyId: CompanyId,
        source: BankImportSource,
        filename: OptionalString255,
        rowCount: OptionalString16,
        importedCount: OptionalString16,
        skippedDuplicates: OptionalString16,
        autoMatchedCount: OptionalString16,
    },
    bankTransaction: {
        id: BankTransactionId,
        companyId: CompanyId,
        bankImportBatchId: nullOr(BankImportBatchId),
        bookedAt: OptionalString32,
        amount: OptionalString32,
        currency: nullOr(CurrencyCode),
        direction: BankTransactionDirection,
        matchStatus: BankTransactionMatchStatus,
        businessExpenseId: nullOr(ExpenseId),
        variableSymbol: OptionalString32,
        constantSymbol: OptionalString16,
        specificSymbol: OptionalString16,
        counterpartyName: OptionalString255,
        counterpartyIban: OptionalString64,
        reference: OptionalString4000,
        bankTransactionId: OptionalString128,
        dedupeHash: OptionalString128,
        source: BankImportSource,
    },
    bankTransactionMatch: {
        id: BankTransactionMatchId,
        bankTransactionId: BankTransactionId,
        businessDocumentId: DocumentId,
        matchedAmount: OptionalString32,
        matchType: BankMatchType,
        matchedAt: OptionalString32,
    },
} satisfies EvoluSchema;

export type InvoicingLocalSchema = typeof InvoicingLocalSchema;

export const EVOLU_APP_NAME = "satflux-invoicing" as const;
