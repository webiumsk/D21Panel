import { createEvolu, kysely, SimpleName, sqliteTrue } from "@evolu/common";
import { createUseEvolu } from "@evolu/vue";
import { evoluTransports } from "./config";
import { createEvoluWebDepsWithReloadGuard } from "./reloadGuard";
import { EVOLU_APP_NAME, InvoicingLocalSchema } from "./schema";

export const evolu = createEvolu(createEvoluWebDepsWithReloadGuard())(InvoicingLocalSchema, {
    name: SimpleName.orThrow(EVOLU_APP_NAME),
    transports: evoluTransports(),
});

export const useInvoicingEvolu = createUseEvolu(evolu);

const companyListColumns = ["id", "legalName", "tradeName", "registrationNumber", "logoDataUrl"] as const;

const companyDetailColumns = [
    "id",
    "legalName",
    "tradeName",
    "jurisdiction",
    "defaultCurrency",
    "registrationNumber",
    "taxId",
    "vatNumber",
    "commercialRegister",
    "registerCourt",
    "registerNumber",
    "managingDirectors",
    "supervisoryBoardChair",
    "street",
    "city",
    "postalCode",
    "country",
    "stateRegion",
    "iban",
    "bic",
    "bankName",
    "bankAccount",
    "bankCode",
    "vatPayer",
    "vatStatus",
    "vatRateDefault",
    "legalFooterNote",
    "issuerName",
    "issuerPhone",
    "issuerEmail",
    "website",
    "invoiceNumberPrefix",
    "linkedStoreId",
    "appSettingsJson",
    "emailSettingsJson",
    "logoDataUrl",
    "signatureDataUrl",
] as const;

const contactColumns = [
    "id",
    "companyId",
    "name",
    "registrationNumber",
    "peppolParticipantId",
    "email",
    "phone",
    "fax",
    "taxId",
    "vatId",
    "street",
    "city",
    "postalCode",
    "stateRegion",
    "country",
    "bankAccount",
    "bankCode",
    "iban",
    "swift",
    "deliveryStreet",
    "deliveryPostalCode",
    "deliveryCity",
    "deliveryCountry",
    "defaultPaymentTermsDays",
    "notes",
    "contactPersonsJson",
    "isActive",
] as const;

const documentColumns = [
    "id",
    "companyId",
    "contactId",
    "documentType",
    "status",
    "quoteStatus",
    "title",
    "number",
    "sourceDocumentId",
    "issueDate",
    "deliveryDate",
    "dueDate",
    "variableSymbol",
    "constantSymbol",
    "specificSymbol",
    "currency",
    "subtotal",
    "taxTotal",
    "discountPercent",
    "total",
    "noteAboveLines",
    "noteFooter",
    "internalNote",
    "pdfLocale",
    "pdfBankQr",
    "pdfShowSignature",
    "pdfShowPaymentInfo",
    "paymentBankEnabled",
    "paymentBtcEnabled",
    "storeId",
    "tagsJson",
    "paidAt",
    "amountPaid",
    "emailSentAt",
] as const;

const documentLineColumns = [
    "id",
    "documentId",
    "sortOrder",
    "name",
    "description",
    "quantity",
    "unit",
    "unitPrice",
    "lineDiscountPercent",
    "taxRate",
    "lineTotal",
    "companyStockItemId",
    "companyWarehouseId",
] as const;

const numberSeriesColumns = [
    "id",
    "companyId",
    "name",
    "documentType",
    "format",
    "resetPeriod",
    "isDefault",
    "periodKey",
    "lastNumber",
] as const;

const documentEventColumns = [
    "id",
    "documentId",
    "action",
    "metadataJson",
    "createdAt",
] as const;

const documentSnapshotColumns = [
    "id",
    "documentId",
    "formatVersion",
    "payloadJson",
    "backfilled",
    "createdAt",
] as const;

export const allCompaniesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("company")
        .select(companyListColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("legalName", "is not", null)
        .$narrowType<{ legalName: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allCompaniesDetailQuery = evolu.createQuery((db) =>
    db
        .selectFrom("company")
        .select(companyDetailColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("legalName", "is not", null)
        .$narrowType<{ legalName: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allContactsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("contact")
        .select(contactColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allDocumentsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("document")
        .select(documentColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("title", "is not", null)
        .$narrowType<{ title: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allDocumentLinesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("documentLine")
        .select(documentLineColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allNumberSeriesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("numberSeries")
        .select(numberSeriesColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allDocumentEventsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("documentEvent")
        .select(documentEventColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("action", "is not", null)
        .$narrowType<{ action: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allDocumentSnapshotsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("documentSnapshot")
        .select(documentSnapshotColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("payloadJson", "is not", null)
        .$narrowType<{ payloadJson: kysely.NotNull }>()
        .orderBy("createdAt"),
);

const expenseColumns = [
    "id",
    "companyId",
    "status",
    "internalNumber",
    "externalNumber",
    "title",
    "variableSymbol",
    "constantSymbol",
    "specificSymbol",
    "issueDate",
    "deliveryDate",
    "dueDate",
    "paidAt",
    "cancelledAt",
    "total",
    "currency",
    "internalNote",
] as const;

export const allExpensesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("expense")
        .select(expenseColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("internalNumber", "is not", null)
        .$narrowType<{ internalNumber: kysely.NotNull }>()
        .orderBy("createdAt"),
);

const expenseAttachmentColumns = [
    "id",
    "expenseId",
    "originalFilename",
    "mimeType",
    "sizeBytes",
    "contentBase64",
] as const;

export const allExpenseAttachmentsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("expenseAttachment")
        .select(expenseAttachmentColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt"),
);

const recurringProfileColumns = [
    "id",
    "companyId",
    "contactId",
    "storeId",
    "documentType",
    "isActive",
    "recurrenceInterval",
    "firstIssueDate",
    "nextIssueDate",
    "endsAt",
    "repeatIndefinitely",
    "issueLastDayOfMonth",
    "title",
    "variableSymbol",
    "constantSymbol",
    "specificSymbol",
    "paymentTermsDays",
    "deliveryDateMode",
    "currency",
    "discountPercent",
    "subtotal",
    "taxTotal",
    "total",
    "noteAboveLines",
    "noteFooter",
    "internalNote",
    "pdfLocale",
    "pdfBankQr",
    "pdfShowSignature",
    "pdfShowPaymentInfo",
    "paymentBtcEnabled",
    "paymentBankEnabled",
    "sendEmailAfterIssue",
    "emailBcc",
    "tagsJson",
    "lastGeneratedDocumentId",
    "lastGeneratedAt",
] as const;

const recurringProfileLineColumns = [
    "id",
    "recurringProfileId",
    "sortOrder",
    "name",
    "description",
    "quantity",
    "unit",
    "unitPrice",
    "lineDiscountPercent",
    "taxRate",
    "lineTotal",
] as const;

export const allRecurringProfilesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("recurringProfile")
        .select(recurringProfileColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt"),
);

export const allRecurringProfileLinesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("recurringProfileLine")
        .select(recurringProfileLineColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

const companyWarehouseColumns = [
    "id",
    "companyId",
    "name",
    "type",
    "deductOnIssue",
    "isDefault",
    "isActive",
    "companyContactId",
    "street",
    "city",
    "postalCode",
    "country",
    "notes",
] as const;

const companyStockItemColumns = [
    "id",
    "companyId",
    "name",
    "sku",
    "description",
    "unit",
    "trackInventory",
    "purchaseUnitPrice",
    "purchaseCurrency",
    "saleUnitPrice",
    "internalNote",
    "excludeFromSuggester",
] as const;

const companyStockBalanceColumns = [
    "id",
    "companyId",
    "companyWarehouseId",
    "companyStockItemId",
    "quantityOnHand",
] as const;

const companyStockMovementColumns = [
    "id",
    "companyId",
    "companyStockItemId",
    "companyWarehouseId",
    "quantityAfter",
    "quantityDelta",
    "purchaseUnitPrice",
    "saleUnitPrice",
    "note",
    "source",
    "businessDocumentId",
    "documentNumber",
    "documentType",
    "movementAt",
] as const;

export const allCompanyWarehousesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("companyWarehouse")
        .select(companyWarehouseColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allCompanyStockItemsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("companyStockItem")
        .select(companyStockItemColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allCompanyStockBalancesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("companyStockBalance")
        .select(companyStockBalanceColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt"),
);

const invoiceTemplateColumns = ["id", "companyId", "name", "payloadJson"] as const;

export const allInvoiceTemplatesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("invoiceTemplate")
        .select(invoiceTemplateColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .where("name", "is not", null)
        .$narrowType<{ name: kysely.NotNull }>()
        .orderBy("createdAt"),
);

export const allCompanyStockMovementsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("companyStockMovement")
        .select(companyStockMovementColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt"),
);

const bankImportBatchColumns = [
    "id",
    "companyId",
    "source",
    "filename",
    "rowCount",
    "importedCount",
    "skippedDuplicates",
    "autoMatchedCount",
    "createdAt",
] as const;

const bankTransactionColumns = [
    "id",
    "companyId",
    "bankImportBatchId",
    "bookedAt",
    "amount",
    "currency",
    "direction",
    "matchStatus",
    "businessExpenseId",
    "variableSymbol",
    "constantSymbol",
    "specificSymbol",
    "counterpartyName",
    "counterpartyIban",
    "reference",
    "bankTransactionId",
    "dedupeHash",
    "source",
] as const;

const bankTransactionMatchColumns = [
    "id",
    "bankTransactionId",
    "businessDocumentId",
    "matchedAmount",
    "matchType",
    "matchedAt",
] as const;

export const allBankImportBatchesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("bankImportBatch")
        .select(bankImportBatchColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt", "desc"),
);

export const allBankTransactionsQuery = evolu.createQuery((db) =>
    db
        .selectFrom("bankTransaction")
        .select(bankTransactionColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt"),
);

export const allBankTransactionMatchesQuery = evolu.createQuery((db) =>
    db
        .selectFrom("bankTransactionMatch")
        .select(bankTransactionMatchColumns)
        .where("isDeleted", "is not", sqliteTrue)
        .orderBy("createdAt"),
);

export { EVOLU_RELAY_URL } from "./config";
