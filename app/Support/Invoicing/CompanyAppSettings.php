<?php

namespace App\Support\Invoicing;

/**
 * Per-company invoicing application preferences (SuperFaktúra-style "Aplikácia").
 */
final class CompanyAppSettings
{
    public const DEFAULTS = [
        'rounding_method' => 'per_line',
        'invoice_line_label' => null,
        'default_invoice_payment_terms_days' => 14,
        'default_delivery_method' => null,
        'default_delivery_date_mode' => 'empty',
        'default_payment_method' => null,
        'pdf_filename_pattern' => '#TYPE#_#COMPANY#_#NUMBER#',
        'expense_attachment_name_pattern' => null,
        'sort_lists_by' => 'issue_date',
        'number_documents_by' => 'issue_date',
        'default_constant_symbol' => '0308',
        'tax_free_minimum' => '0.00',
        'show_contextual_help' => true,
        'embed_isdoc_in_pdf' => true,
        // ZUGFeRD hybrid PDFs for DE companies (factur-x.xml in the PDF).
        'embed_zugferd_in_pdf' => true,
        'reverse_charge' => false,
        'reverse_charge_note' => null,
        // DE non-EU exports are goods (G, "Steuerfreie Ausfuhrlieferung."),
        // not services (O) - drives the EN 16931 category and default clause.
        'export_goods' => false,
        // Custom DE export clause override (defaults to the statutory wording).
        'export_note' => null,
        'us_sales_tax_provider' => 'manual',
        'stripe_tax_secret_key' => null,
        'show_pay_by_square' => true,
        'show_invoice_by_square' => false,
        'show_client_phone_on_invoices' => false,
        'show_payme_on_invoices' => false,
        'variable_symbol_from_proforma' => false,
        'show_prices_on_delivery_notes' => false,
        'show_prices_on_orders' => true,
        'show_line_suggester' => true,
        'show_summary_on_quotes' => true,
        'runs_eshop' => false,
        'efaktura_enabled' => false,
        'efaktura_auto_send' => false,
        'efaktura_inbound_enabled' => false,
        'efaktura_provider' => 'sapi_sk',
        'efaktura_peppol_participant_id' => null,
        'efaktura_sapi_base_url' => null,
        'efaktura_sapi_client_id' => null,
        'efaktura_sapi_client_secret_encrypted' => null,
    ];

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(public array $values = []) {}

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function from(?array $stored): self
    {
        $merged = array_merge(self::DEFAULTS, $stored ?? []);

        return new self($merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }
}
