export type CompanyAppSettingsState = {
  rounding_method: 'per_line' | 'per_document' | 'none';
  invoice_line_label: string;
  default_invoice_payment_terms_days: number;
  default_delivery_method: string;
  default_delivery_date_mode: 'empty' | 'issue_date' | 'due_date';
  default_payment_method: string;
  pdf_filename_pattern: string;
  expense_attachment_name_pattern: string;
  sort_lists_by: 'issue_date' | 'due_date' | 'number';
  number_documents_by: 'issue_date' | 'calendar_year';
  default_constant_symbol: string;
  tax_free_minimum: string;
  show_contextual_help: boolean;
  embed_isdoc_in_pdf: boolean;
  embed_zugferd_in_pdf: boolean;
  reverse_charge: boolean;
  reverse_charge_note: string;
  export_goods: boolean;
  export_note: string;
  us_sales_tax_provider: 'manual' | 'stripe_tax' | 'avalara';
  stripe_tax_secret_key: string;
  show_pay_by_square: boolean;
  show_invoice_by_square: boolean;
  show_client_phone_on_invoices: boolean;
  show_payme_on_invoices: boolean;
  variable_symbol_from_proforma: boolean;
  show_prices_on_delivery_notes: boolean;
  show_prices_on_orders: boolean;
  show_line_suggester: boolean;
  show_summary_on_quotes: boolean;
  runs_eshop: boolean;
};

export const PDF_FILENAME_SHORTCUTS = [
  { token: '#TITLE#', key: 'invoicing.pdf_token_name' },
  { token: '#TYPE#', key: 'invoicing.pdf_token_type' },
  { token: '#COMPANY#', key: 'invoicing.pdf_token_company' },
  { token: '#NUMBER#', key: 'invoicing.pdf_token_number' },
  { token: '#CLIENT#', key: 'invoicing.pdf_token_client' },
  { token: '#ISSUE_DATE#', key: 'invoicing.pdf_token_issue_date' },
  { token: '#AMOUNT#', key: 'invoicing.pdf_token_total' },
  { token: '#CURRENCY#', key: 'invoicing.pdf_token_currency' },
] as const;

export function defaultAppSettings(): CompanyAppSettingsState {
  return {
    rounding_method: 'per_line',
    invoice_line_label: '',
    default_invoice_payment_terms_days: 14,
    default_delivery_method: '',
    default_delivery_date_mode: 'empty',
    default_payment_method: '',
    pdf_filename_pattern: '#TYPE#_#COMPANY#_#NUMBER#',
    expense_attachment_name_pattern: '',
    sort_lists_by: 'issue_date',
    number_documents_by: 'issue_date',
    default_constant_symbol: '0308',
    tax_free_minimum: '0.00',
    show_contextual_help: true,
    embed_isdoc_in_pdf: true,
    embed_zugferd_in_pdf: true,
    reverse_charge: false,
    reverse_charge_note: '',
    export_goods: false,
    export_note: '',
    us_sales_tax_provider: 'manual',
    stripe_tax_secret_key: '',
    show_pay_by_square: true,
    show_invoice_by_square: false,
    show_client_phone_on_invoices: false,
    show_payme_on_invoices: false,
    variable_symbol_from_proforma: false,
    show_prices_on_delivery_notes: false,
    show_prices_on_orders: true,
    show_line_suggester: true,
    show_summary_on_quotes: true,
    runs_eshop: false,
  };
}

export function appSettingsFromCompany(company: Record<string, unknown> | null): CompanyAppSettingsState {
  const raw = (company?.app_settings ?? {}) as Partial<CompanyAppSettingsState>;
  const base = defaultAppSettings();

  return {
    rounding_method: raw.rounding_method ?? base.rounding_method,
    invoice_line_label: raw.invoice_line_label ?? '',
    default_invoice_payment_terms_days:
      raw.default_invoice_payment_terms_days ?? base.default_invoice_payment_terms_days,
    default_delivery_method: raw.default_delivery_method ?? base.default_delivery_method,
    default_delivery_date_mode: raw.default_delivery_date_mode ?? base.default_delivery_date_mode,
    default_payment_method: raw.default_payment_method ?? base.default_payment_method,
    pdf_filename_pattern: raw.pdf_filename_pattern ?? base.pdf_filename_pattern,
    expense_attachment_name_pattern: raw.expense_attachment_name_pattern ?? base.expense_attachment_name_pattern,
    sort_lists_by: raw.sort_lists_by ?? base.sort_lists_by,
    number_documents_by: raw.number_documents_by ?? base.number_documents_by,
    default_constant_symbol: raw.default_constant_symbol ?? base.default_constant_symbol,
    tax_free_minimum: String(raw.tax_free_minimum ?? base.tax_free_minimum),
    show_contextual_help: raw.show_contextual_help ?? base.show_contextual_help,
    embed_isdoc_in_pdf: raw.embed_isdoc_in_pdf ?? base.embed_isdoc_in_pdf,
    embed_zugferd_in_pdf: raw.embed_zugferd_in_pdf ?? base.embed_zugferd_in_pdf,
    reverse_charge: raw.reverse_charge ?? base.reverse_charge,
    reverse_charge_note: raw.reverse_charge_note ?? base.reverse_charge_note,
    export_goods: raw.export_goods ?? base.export_goods,
    export_note: raw.export_note ?? base.export_note,
    us_sales_tax_provider: raw.us_sales_tax_provider ?? base.us_sales_tax_provider,
    stripe_tax_secret_key: raw.stripe_tax_secret_key ?? base.stripe_tax_secret_key,
    show_pay_by_square: raw.show_pay_by_square ?? base.show_pay_by_square,
    show_invoice_by_square: raw.show_invoice_by_square ?? base.show_invoice_by_square,
    show_client_phone_on_invoices: raw.show_client_phone_on_invoices ?? base.show_client_phone_on_invoices,
    show_payme_on_invoices: raw.show_payme_on_invoices ?? base.show_payme_on_invoices,
    variable_symbol_from_proforma: raw.variable_symbol_from_proforma ?? base.variable_symbol_from_proforma,
    show_prices_on_delivery_notes: raw.show_prices_on_delivery_notes ?? base.show_prices_on_delivery_notes,
    show_prices_on_orders: raw.show_prices_on_orders ?? base.show_prices_on_orders,
    show_line_suggester: raw.show_line_suggester ?? base.show_line_suggester,
    show_summary_on_quotes: raw.show_summary_on_quotes ?? base.show_summary_on_quotes,
    runs_eshop: raw.runs_eshop ?? base.runs_eshop,
  };
}
