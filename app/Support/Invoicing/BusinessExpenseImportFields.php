<?php

namespace App\Support\Invoicing;

use Illuminate\Support\Str;

/**
 * Excel expense import column definitions (internal keys are English).
 */
final class BusinessExpenseImportFields
{
    /**
     * SuperFaktúra-style example headers.
     *
     * @var list<string>
     */
    public const EXAMPLE_HEADERS = [
        'Názov',
        'Kategória',
        'Dodávateľ',
        'Číslo dokladu',
        'Interné číslo',
        'Dátum vystavenia',
        'Dátum splatnosti',
        'Dátum dodania',
        'Spolu',
        'Mena',
        'Dátum úhrady',
        'Variabilný symbol',
        'Konštantný symbol',
        'Špecifický symbol',
        'Tagy',
        'Interná poznámka',
        'Dodávateľ - IČO',
        'Dodávateľ - DIČ',
        'Dodávateľ - E-mail',
        'Dodávateľ - Ulica',
        'Dodávateľ - Mesto',
        'Dodávateľ - Štát',
    ];

    /**
     * @return list<array{key: string, required: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => 'title', 'required' => false],
            ['key' => 'category', 'required' => false],
            ['key' => 'supplier_name', 'required' => false],
            ['key' => 'external_number', 'required' => false],
            ['key' => 'internal_number', 'required' => false],
            ['key' => 'issue_date', 'required' => true],
            ['key' => 'due_date', 'required' => false],
            ['key' => 'delivery_date', 'required' => false],
            ['key' => 'total', 'required' => true],
            ['key' => 'currency', 'required' => false],
            ['key' => 'paid_at', 'required' => false],
            ['key' => 'variable_symbol', 'required' => false],
            ['key' => 'constant_symbol', 'required' => false],
            ['key' => 'specific_symbol', 'required' => false],
            ['key' => 'tags', 'required' => false],
            ['key' => 'internal_note', 'required' => false],
            ['key' => 'supplier_registration_number', 'required' => false],
            ['key' => 'supplier_tax_id', 'required' => false],
            ['key' => 'supplier_email', 'required' => false],
            ['key' => 'supplier_street', 'required' => false],
            ['key' => 'supplier_city', 'required' => false],
            ['key' => 'supplier_country', 'required' => false],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function headerAliases(): array
    {
        $map = [
            'title' => ['nazov', 'name', 'title', 'popis'],
            'category' => ['kategoria', 'category'],
            'supplier_name' => ['dodavatel', 'supplier', 'supplier name'],
            'external_number' => [
                'cislo dokladu', 'cislo dokl', 'cislo dokladu dodavatela', 'external number', 'invoice number',
            ],
            'internal_number' => ['interne cislo', 'interne cis', 'internal number'],
            'issue_date' => ['datum vystavenia', 'datum vy', 'issue date', 'vystavenie'],
            'due_date' => ['datum splatnosti', 'datum sp', 'due date', 'splatnost'],
            'delivery_date' => ['datum dodania', 'datum do', 'delivery date'],
            'total' => ['spolu', 'suma', 'total', 'amount'],
            'currency' => ['mena', 'currency'],
            'paid_at' => ['datum uhrady', 'datum uh', 'paid at', 'payment date'],
            'variable_symbol' => ['variabilny symbol', 'vs', 'variable symbol'],
            'constant_symbol' => ['konstantny symbol', 'ks', 'constant symbol'],
            'specific_symbol' => ['specificky symbol', 'ss', 'specific symbol'],
            'tags' => ['tagy', 'tags'],
            'internal_note' => ['interna poznamka', 'interna p', 'poznamka', 'note', 'notes'],
            'supplier_registration_number' => [
                'dodavatel - ico', 'dodavatel ico', 'ico', 'supplier registration number',
            ],
            'supplier_tax_id' => ['dodavatel - dic', 'dodavatel dic', 'dic', 'supplier tax id'],
            'supplier_email' => ['dodavatel - e-mail', 'dodavatel - email', 'dodavatel email', 'supplier email'],
            'supplier_street' => ['dodavatel - ulica', 'dodavatel ulica', 'supplier street'],
            'supplier_city' => ['dodavatel - mesto', 'dodavatel mesto', 'supplier city'],
            'supplier_country' => ['dodavatel - stat', 'dodavatel stat', 'supplier country'],
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
