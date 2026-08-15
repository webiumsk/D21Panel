<?php

namespace App\Services\Invoicing;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyWarehouse;
use App\Support\Invoicing\CompanyStockItemImportFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyStockItemImportService
{
    private const MAX_ROWS = 5000;

    public function __construct(
        protected CompanyStockMovementService $movementService,
        protected CompanyWarehouseService $warehouseService,
        protected CompanyStockBalanceService $balanceService,
    ) {}

    /**
     * @return array{headers: list<string>, suggested_mapping: array<string, int|null>, row_count: int, preview: list<array<string, mixed>>}
     */
    public function preview(Company $company, UploadedFile $file, ?array $mapping = null): array
    {
        $parsed = $this->parseSpreadsheet($file);
        $mapping = $this->normalizeMapping($mapping ?? $this->suggestMapping($parsed['headers']));

        $preview = [];
        foreach (array_slice($parsed['rows'], 0, 10) as $index => $row) {
            try {
                $payload = $this->rowToPayload($row, $mapping);
                if ($payload === null) {
                    $preview[] = ['row' => $index + 2, 'error' => 'Item name is required.'];
                } else {
                    $preview[] = array_merge(['row' => $index + 2], $this->payloadToPreview($payload));
                }
            } catch (\InvalidArgumentException $e) {
                $preview[] = ['row' => $index + 2, 'error' => $e->getMessage()];
            }
        }

        return [
            'headers' => $parsed['headers'],
            'suggested_mapping' => $mapping,
            'row_count' => count($parsed['rows']),
            'preview' => $preview,
        ];
    }

    /**
     * @param  array<string, int|string|null>|null  $mapping
     * @return array{imported: int, updated: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function importFile(Company $company, UploadedFile $file, ?array $mapping = null): array
    {
        $parsed = $this->parseSpreadsheet($file);
        $mapping = $this->normalizeMapping($mapping ?? $this->suggestMapping($parsed['headers']));

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        foreach ($parsed['rows'] as $row) {
            if ($imported + $updated + $skipped >= self::MAX_ROWS) {
                $errors[] = [
                    'row' => $rowNumber + 1,
                    'message' => 'Row limit exceeded ('.self::MAX_ROWS.').',
                ];
                break;
            }

            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            try {
                $payload = $this->rowToPayload($row, $mapping);
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];

                continue;
            }

            if (! $payload) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => 'Item name is required.'];

                continue;
            }

            try {
                DB::transaction(function () use ($company, $payload, &$imported, &$updated) {
                    $importRef = $payload['import_document_ref'] ?? null;
                    $warehouseName = $payload['warehouse_name'] ?? null;
                    $quantityOnHand = array_key_exists('quantity_on_hand', $payload)
                        ? (float) $payload['quantity_on_hand']
                        : null;
                    unset(
                        $payload['import_document_ref'],
                        $payload['warehouse_name'],
                        $payload['quantity_on_hand'],
                    );

                    $warehouse = $this->resolveImportWarehouse($company, $warehouseName);
                    $note = $importRef ? 'Hromadný import skladu ('.$importRef.')' : 'Hromadný import skladu';

                    $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
                    $existing = null;
                    if ($sku !== '') {
                        $existing = $company->stockItems()->where('sku', $sku)->first();
                    }

                    if ($existing) {
                        $previousQuantity = $this->balanceService->getQuantity($warehouse, $existing);
                        $existing->fill($payload);
                        $existing->save();
                        if ($quantityOnHand !== null) {
                            $this->balanceService->setQuantity($warehouse, $existing, $quantityOnHand);
                            $this->movementService->recordImportChange($existing, $warehouse, $previousQuantity, $note);
                        }
                        AuditLog::log('stock_item.import.update', 'company_stock_item', $existing->id, [
                            'company_id' => $company->id,
                            'sku' => $existing->sku,
                            'warehouse_id' => $warehouse->id,
                            'warehouse_name' => $warehouse->name,
                            'previous_quantity' => $previousQuantity,
                            'new_quantity' => $quantityOnHand,
                            'import_document_ref' => $importRef,
                        ]);
                        $updated++;
                    } else {
                        $item = $company->stockItems()->create($payload);
                        if ($quantityOnHand !== null && $quantityOnHand !== 0.0) {
                            $this->balanceService->setQuantity($warehouse, $item, $quantityOnHand);
                            $this->movementService->recordImportChange($item, $warehouse, 0.0, $note);
                        }
                        AuditLog::log('stock_item.import.create', 'company_stock_item', $item->id, [
                            'company_id' => $company->id,
                            'sku' => $item->sku,
                            'warehouse_id' => $warehouse->id,
                            'warehouse_name' => $warehouse->name,
                            'new_quantity' => $quantityOnHand,
                            'import_document_ref' => $importRef,
                        ]);
                        $imported++;
                    }
                });
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function exampleDownloadResponse(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([CompanyStockItemImportFields::EXAMPLE_HEADERS], null, 'A1');
        $sheet->fromArray([
            [
                'Jablká',
                'Jab-123',
                '1.5',
                '1',
                'EUR',
                'kg',
                '13',
                '',
                'Odroda Red Delicious',
                'FA001',
                '',
            ],
            [
                'Drevená bednička',
                'bednicka3',
                '1',
                '0.6',
                'CZK',
                'ks',
                '0',
                '',
                'Drevená bednička, nosnosť 3kg',
                '',
                '',
            ],
        ], null, 'A2');

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'stock_import_example.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{headers: list<string>, rows: list<list<mixed>>}
     */
    protected function parseSpreadsheet(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path) {
            throw new \InvalidArgumentException('Could not read uploaded file.');
        }

        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if (count($rows) < 2) {
            throw new \InvalidArgumentException('The spreadsheet has no data rows.');
        }

        $headerRow = array_shift($rows);
        $headers = array_map(fn ($h) => trim((string) ($h ?? '')), $headerRow);

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public function suggestMapping(array $headers): array
    {
        $aliases = CompanyStockItemImportFields::headerAliases();
        $mapping = [];

        foreach (CompanyStockItemImportFields::definitions() as $def) {
            $mapping[$def['key']] = null;
        }

        foreach ($headers as $index => $header) {
            if ($header === '' || CompanyStockItemImportFields::isInstructionColumn($header)) {
                continue;
            }
            $normalized = CompanyStockItemImportFields::normalizeHeader($header);
            $field = $aliases[$normalized] ?? null;
            if ($field !== null && $mapping[$field] === null) {
                $mapping[$field] = $index;
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, int|string|null>  $mapping
     * @return array<string, int|null>
     */
    protected function normalizeMapping(array $mapping): array
    {
        $normalized = [];
        foreach (CompanyStockItemImportFields::definitions() as $def) {
            $value = $mapping[$def['key']] ?? null;
            if ($value === '' || $value === null) {
                $normalized[$def['key']] = null;

                continue;
            }
            $normalized[$def['key']] = (int) $value;
        }

        foreach (CompanyStockItemImportFields::definitions() as $def) {
            if ($def['required'] && $normalized[$def['key']] === null) {
                throw new \InvalidArgumentException('Missing required column mapping: '.$def['key']);
            }
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int|null>  $mapping
     * @return array<string, mixed>|null
     */
    protected function rowToPayload(array $row, array $mapping): ?array
    {
        $data = [
            'name' => null,
            'sku' => null,
            'sale_unit_price' => null,
            'purchase_unit_price' => null,
            'purchase_currency' => null,
            'unit' => 'ks',
            'warehouse_name' => null,
            'description' => null,
            'import_document_ref' => null,
            'internal_note' => null,
            'track_inventory' => true,
            'exclude_from_suggester' => false,
        ];

        foreach ($mapping as $field => $index) {
            if ($index === null) {
                continue;
            }

            $value = $this->cellString($row[$index] ?? null);
            if ($value === null) {
                continue;
            }

            if (in_array($field, ['sale_unit_price', 'purchase_unit_price', 'quantity_on_hand'], true)) {
                $normalized = str_replace([' ', ','], ['', '.'], $value);
                if (! is_numeric($normalized)) {
                    throw new \InvalidArgumentException('Invalid numeric value for '.$field.'.');
                }
                $data[$field] = $field === 'quantity_on_hand'
                    ? (float) $normalized
                    : round((float) $normalized, 2);

                continue;
            }

            if ($field === 'purchase_currency') {
                $data[$field] = strtoupper(substr($value, 0, 3));

                continue;
            }

            if (array_key_exists($field, $data)) {
                $data[$field] = $value;
            }
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $data['name'] = $name;
        if ($data['sku'] !== null) {
            $data['sku'] = trim((string) $data['sku']) ?: null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function payloadToPreview(array $payload): array
    {
        return [
            'name' => $payload['name'] ?? null,
            'sku' => $payload['sku'] ?? null,
            'sale_unit_price' => $payload['sale_unit_price'] ?? null,
            'quantity_on_hand' => $payload['quantity_on_hand'] ?? null,
            'warehouse_name' => $payload['warehouse_name'] ?? null,
            'unit' => $payload['unit'] ?? null,
        ];
    }

    protected function resolveImportWarehouse(Company $company, ?string $warehouseName): CompanyWarehouse
    {
        $name = trim((string) $warehouseName);
        if ($name !== '') {
            $warehouse = $company->warehouses()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($warehouse) {
                return $warehouse;
            }
        }

        return $this->warehouseService->defaultWarehouse($company);
    }

    /**
     * @param  list<mixed>  $row
     */
    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->cellString($cell) !== null) {
                return false;
            }
        }

        return true;
    }

    protected function cellString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        } elseif (is_int($value) || is_float($value)) {
            $value = trim((string) $value);
        } else {
            return null;
        }

        return $value === '' ? null : $value;
    }
}
