<?php

namespace App\Support\Invoicing;

use Illuminate\Support\Str;

/**
 * Excel contact import column definitions (internal keys are English).
 */
final class CompanyContactImportFields
{
    /**
     * SuperFaktúra-compatible example headers (row 1).
     *
     * @var list<string>
     */
    public const EXAMPLE_HEADERS = [
        'Názov klienta',
        'Ulica',
        'PSČ',
        'Mesto',
        'Štát (skratka)',
        'IČO',
        'DIČ',
        'IČ DPH',
        'E-mail',
        'Telefón',
        'Fax',
        'Poštová adresa - Názov',
        'Poštová adresa - Ulica',
        'Poštová adresa - PSČ',
        'Poštová adresa - Mesto',
        'Poštová adresa - Štát (skratka)',
        'Web',
        'Poznámka',
        'Splatnosť (dni)',
        'Zľava (%)',
        'Mena',
        'Bankový účet - IBAN',
        'Bankový účet - BIC / SWIFT',
    ];

    /**
     * @return list<array{key: string, required: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => 'name', 'required' => true],
            ['key' => 'street', 'required' => false],
            ['key' => 'postal_code', 'required' => false],
            ['key' => 'city', 'required' => false],
            ['key' => 'country', 'required' => false],
            ['key' => 'registration_number', 'required' => false],
            ['key' => 'tax_id', 'required' => false],
            ['key' => 'vat_id', 'required' => false],
            ['key' => 'email', 'required' => false],
            ['key' => 'phone', 'required' => false],
            ['key' => 'fax', 'required' => false],
            ['key' => 'delivery_name', 'required' => false],
            ['key' => 'delivery_street', 'required' => false],
            ['key' => 'delivery_postal_code', 'required' => false],
            ['key' => 'delivery_city', 'required' => false],
            ['key' => 'delivery_country', 'required' => false],
            ['key' => 'web', 'required' => false],
            ['key' => 'notes', 'required' => false],
            ['key' => 'default_payment_terms_days', 'required' => false],
            ['key' => 'iban', 'required' => false],
            ['key' => 'swift', 'required' => false],
        ];
    }

    /**
     * @return array<string, string> normalized alias => field key
     */
    public static function headerAliases(): array
    {
        $map = [
            'name' => [
                'nazov klienta', 'name', 'client name', 'meno', 'odberatel', 'nazov',
            ],
            'street' => ['ulica', 'street', 'address', 'adresa'],
            'postal_code' => ['psc', 'postal code', 'zip'],
            'city' => ['mesto', 'city'],
            'country' => ['stat (skratka)', 'stat', 'krajina', 'country'],
            'registration_number' => ['ico', 'registration number', 'ic'],
            'tax_id' => ['dic', 'tax id'],
            'vat_id' => ['ic dph', 'vat id', 'dph'],
            'email' => ['e-mail', 'email'],
            'phone' => ['telefon', 'phone'],
            'fax' => ['fax'],
            'delivery_name' => ['postova adresa - nazov', 'delivery name', 'postal name'],
            'delivery_street' => ['postova adresa - ulica', 'delivery street'],
            'delivery_postal_code' => ['postova adresa - psc', 'delivery postal code'],
            'delivery_city' => ['postova adresa - mesto', 'delivery city'],
            'delivery_country' => ['postova adresa - stat (skratka)', 'postova adresa - stat', 'delivery country'],
            'web' => ['web', 'website'],
            'notes' => ['poznamka', 'note', 'notes'],
            'default_payment_terms_days' => ['splatnost (dni)', 'payment terms (days)', 'splatnost'],
            'iban' => ['bankovy ucet - iban', 'iban'],
            'swift' => ['bankovy ucet - bic / swift', 'bankovy ucet - bic/swift', 'swift', 'bic'],
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
