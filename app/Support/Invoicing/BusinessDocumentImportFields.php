<?php

namespace App\Support\Invoicing;

use Illuminate\Support\Str;

/**
 * Excel / third-party invoice import column definitions (internal keys are English).
 */
final class BusinessDocumentImportFields
{
    /**
     * @return list<array{key: string, required: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => 'invoice_number', 'required' => true],
            ['key' => 'variable_symbol', 'required' => false],
            ['key' => 'constant_symbol', 'required' => false],
            ['key' => 'specific_symbol', 'required' => false],
            ['key' => 'issue_date', 'required' => true],
            ['key' => 'delivery_date', 'required' => false],
            ['key' => 'due_date', 'required' => true],
            ['key' => 'client_registration_number', 'required' => false],
            ['key' => 'client_tax_id', 'required' => false],
            ['key' => 'client_vat_id', 'required' => false],
            ['key' => 'client_name', 'required' => true],
            ['key' => 'client_street', 'required' => false],
            ['key' => 'client_city', 'required' => false],
            ['key' => 'client_postal_code', 'required' => false],
            ['key' => 'client_country', 'required' => false],
            ['key' => 'client_phone', 'required' => false],
            ['key' => 'client_email', 'required' => false],
            ['key' => 'amount', 'required' => true],
            ['key' => 'currency', 'required' => false],
            ['key' => 'paid_at', 'required' => false],
            ['key' => 'payment_method', 'required' => false],
        ];
    }

    /**
     * @return array<string, list<string>> normalized alias => field key
     */
    public static function headerAliases(): array
    {
        $map = [
            'invoice_number' => [
                'c. faktury', 'cislo faktury', 'cislo dokladu', 'invoice number', 'number', 'cislo',
            ],
            'variable_symbol' => ['variabilny symbol', 'variable symbol', 'vs'],
            'constant_symbol' => ['konstantny symbol', 'constant symbol', 'ks'],
            'specific_symbol' => ['specificky symbol', 'specific symbol', 'ss'],
            'issue_date' => ['vytvorene', 'datum vystavenia', 'issue date', 'created', 'datum vytvorenia'],
            'delivery_date' => ['datum dodania', 'delivery date'],
            'due_date' => ['datum splatnosti', 'due date', 'splatnost'],
            'client_registration_number' => ['ico klienta', 'ico', 'registration number', 'ic'],
            'client_tax_id' => ['dic klienta', 'dic', 'tax id'],
            'client_vat_id' => ['ic dph klienta', 'ic dph', 'vat id', 'dph'],
            'client_name' => ['nazov / meno', 'nazov klienta', 'meno', 'client name', 'name', 'odberatel'],
            'client_street' => ['adresa klienta', 'ulica', 'street', 'address'],
            'client_city' => ['mesto klienta', 'mesto', 'city'],
            'client_postal_code' => ['psc', 'postal code', 'zip'],
            'client_country' => ['krajina klienta (kod iso)', 'krajina klienta', 'krajina', 'country'],
            'client_phone' => ['telefon', 'phone'],
            'client_email' => ['email', 'e-mail'],
            'amount' => ['suma', 'amount', 'total', 'cena'],
            'currency' => ['fakturacna mena (kod iso)', 'mena', 'currency'],
            'paid_at' => ['datum uhrady', 'paid at', 'payment date'],
            'payment_method' => ['forma uhrady', 'payment method'],
        ];

        $normalized = [];
        foreach ($map as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normalized[self::normalizeHeader($alias)] = $field;
            }
        }

        return $normalized;
    }

    public static function normalizeHeader(string $header): string
    {
        $header = trim(mb_strtolower($header));
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return Str::ascii($header);
    }
}
