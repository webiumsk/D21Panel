<?php

namespace App\Support\Invoicing;

use Illuminate\Support\Str;

/**
 * Excel stock item import column definitions (SuperFaktúra-compatible).
 */
final class CompanyStockItemImportFields
{
    /**
     * SuperFaktúra neplatiteľ DPH v3 example headers (row 1).
     *
     * @var list<string>
     */
    public const EXAMPLE_HEADERS = [
        'Názov položky',
        'SKU',
        'Predajná cena',
        'Nákupná cena',
        'Nákupná mena [ISO kód]',
        'Merná jednotka položky',
        'Stav skladu alebo zmena stavu',
        'Sklad',
        'Popis',
        'Doklad',
        'Interná poznámka',
    ];

    /**
     * @return list<array{key: string, required: bool}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => 'name', 'required' => true],
            ['key' => 'sku', 'required' => false],
            ['key' => 'sale_unit_price', 'required' => false],
            ['key' => 'purchase_unit_price', 'required' => false],
            ['key' => 'purchase_currency', 'required' => false],
            ['key' => 'unit', 'required' => false],
            ['key' => 'quantity_on_hand', 'required' => false],
            ['key' => 'warehouse_name', 'required' => false],
            ['key' => 'description', 'required' => false],
            ['key' => 'import_document_ref', 'required' => false],
            ['key' => 'internal_note', 'required' => false],
        ];
    }

    /**
     * @return array<string, string> normalized alias => field key
     */
    public static function headerAliases(): array
    {
        $map = [
            'name' => [
                'nazov polozky', 'name', 'item name', 'polozka',
            ],
            'sku' => ['sku', 'kod', 'stock keeping unit'],
            'sale_unit_price' => [
                'predajna cena', 'sale price', 'unit sale price', 'cena predaj',
            ],
            'purchase_unit_price' => [
                'nakupna cena', 'purchase price', 'unit purchase price', 'cena nakup',
            ],
            'purchase_currency' => [
                'nakupna mena [iso kod]', 'nakupna mena', 'purchase currency', 'mena nakup',
            ],
            'unit' => [
                'merna jednotka polozky', 'merna jednotka', 'unit', 'jednotka',
            ],
            'quantity_on_hand' => [
                'stav skladu alebo zmena stavu', 'stav skladu', 'stock', 'quantity', 'mnozstvo',
            ],
            'warehouse_name' => ['sklad', 'warehouse', 'lokacia', 'location'],
            'description' => ['popis', 'description'],
            'import_document_ref' => ['doklad', 'document', 'document ref'],
            'internal_note' => ['interna poznamka', 'internal note', 'poznamka'],
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

    public static function isInstructionColumn(string $header): bool
    {
        $normalized = self::normalizeHeader($header);

        return str_contains($normalized, 'import skladovych poloziek')
            || str_contains($normalized, 'vzorova tabulka');
    }
}
