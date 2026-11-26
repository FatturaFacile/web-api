<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\VatCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\ProductCatalog\Domain\Enums\DeltaType;
use Modules\ProductCatalog\Domain\Enums\ImportMode;
use Modules\ProductCatalog\Domain\Enums\KitType;
use Modules\ProductCatalog\Domain\Enums\PackagingRole;
use Modules\ProductCatalog\Domain\Enums\ProductType;
use Modules\ProductCatalog\Domain\Support\VariantPricing;
use Modules\ProductCatalog\Exceptions\CatalogException;
use Modules\ProductCatalog\Exceptions\ImportAborted;
use Modules\ProductCatalog\Models\CatalogAttribute;
use Modules\ProductCatalog\Models\CatalogAttributeValue;
use Modules\ProductCatalog\Models\CatalogVariantValue;

/**
 * Bulk import of the catalog from a .csv or .xlsx file. The header is checked against the schema first, then every row is
 * validated (types, enumerations, GS1 and customs formats, units, VAT codes, parents, kit components) before anything is written.
 * Modes: `dry_run` validates only; `atomic` saves everything or nothing; `partial` saves the valid rows and reports the others.
 * Products are matched by SKU (created when new, updated otherwise); variants (a `parent_sku`) are written after their parents
 * and kit components after all products, so one file can bring a whole family.
 */
final class CatalogImportService
{
    public function __construct(
        private readonly CatalogSpreadsheet $spreadsheet,
        private readonly CatalogProductService $products,
        private readonly PackagingService $packagings,
        private readonly KitService $kits,
    ) {}

    /**
     * @return array{mode: string, committed: bool, total: int, created: int, updated: int, failed: int, errors: list<array{line: int, sku: string|null, field: string, message: string}>}
     *
     * @throws CatalogException
     */
    public function import(string $path, string $extension, ImportMode $mode): array
    {
        $rows = $this->spreadsheet->read($path, $extension);
        $errors = [];
        $valid = [];
        $skus = [];

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $sku = $cells['sku'] ?? null;
            $rowErrors = $this->validate($cells);

            if ($sku !== null) {
                $key = strtoupper($sku);

                if (isset($skus[$key])) {
                    $rowErrors[] = ['field' => 'sku', 'message' => __('product_catalog.import.duplicate_sku', ['sku' => $sku, 'line' => $skus[$key]])];
                }

                $skus[$key] ??= $row['line'];
            }

            if ($rowErrors === []) {
                $valid[] = $row;
            }

            foreach ($rowErrors as $error) {
                $errors[] = ['line' => $row['line'], 'sku' => $sku, 'field' => $error['field'], 'message' => $error['message']];
            }
        }

        $valid = $this->crossChecks($valid, $skus, $errors);
        $report = ['mode' => $mode->value, 'committed' => false, 'total' => count($rows), 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => $errors];

        if ($mode === ImportMode::DryRun) {
            [$report['created'], $report['updated']] = $this->countOutcomes($valid);
            $report['failed'] = $this->failedRows($errors);

            return $report;
        }

        if ($mode === ImportMode::Atomic && $errors !== []) {
            $report['failed'] = $this->failedRows($errors);

            return $report;
        }

        try {
            $outcome = $mode === ImportMode::Atomic
                ? DB::transaction(fn (): array => $this->apply($valid, false))
                : $this->apply($valid, true);
        } catch (ImportAborted $aborted) {
            $report['errors'] = [...$errors, ...$aborted->errors];
            $report['failed'] = $this->failedRows($report['errors']);

            return $report;
        }

        $report['errors'] = [...$errors, ...$outcome['errors']];
        $report['created'] = $outcome['created'];
        $report['updated'] = $outcome['updated'];
        $report['failed'] = $this->failedRows($report['errors']);
        $report['committed'] = true;

        return $report;
    }

    /**
     * @param  array<string, string|null>  $cells
     * @return list<array{field: string, message: string}>
     */
    private function validate(array $cells): array
    {
        $cells = $this->typed($cells);
        $validator = Validator::make($cells, [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'product_type' => ['nullable', Rule::in(ProductType::values())],
            'kit_type' => ['nullable', Rule::in(KitType::values())],
            'parent_sku' => ['nullable', 'string', 'max:50'],
            'stock_uom' => ['nullable', 'string', 'max:20'],
            'barcode' => ['nullable', 'string', 'max:50'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'price_delta' => ['nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'cost_delta' => ['nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            'price_delta_type' => ['nullable', Rule::in(DeltaType::values())],
            'cost_delta_type' => ['nullable', Rule::in(DeltaType::values())],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tares_code' => ['nullable', 'regex:/^\d{8}$/'],
            'taric_code' => ['nullable', 'regex:/^\d{6}(\d{2}(\d{2})?)?$/'],
            'country_of_origin' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'vat_code_it' => ['nullable', 'string', 'max:40'],
            'vat_code_ch' => ['nullable', 'string', 'max:40'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'track_inventory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $errors = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = ['field' => $field, 'message' => $message];
            }
        }

        foreach (['packagings' => $this->parsePackagings($cells['packagings'] ?? null), 'kit_components' => $this->parseKit($cells['kit_components'] ?? null)] as $field => $parsed) {
            if ($parsed === null) {
                $errors[] = ['field' => $field, 'message' => __('product_catalog.import.bad_structure', ['field' => $field])];
            }
        }

        if (($cells['attributes'] ?? null) !== null && $this->parseAttributes((string) $cells['attributes']) === null) {
            $errors[] = ['field' => 'attributes', 'message' => __('product_catalog.import.bad_structure', ['field' => 'attributes'])];
        }

        return $errors;
    }

    /**
     * Checks that need the database or the other rows: units, VAT codes, parents, components and the type rules of kits.
     *
     * @param  list<array{line: int, cells: array<string, string|null>}>  $rows
     * @param  array<string, int>  $fileSkus
     * @param  list<array{line: int, sku: string|null, field: string, message: string}>  $errors
     * @return list<array{line: int, cells: array<string, string|null>}>
     */
    private function crossChecks(array $rows, array $fileSkus, array &$errors): array
    {
        $units = UnitOfMeasure::query()->pluck('id', 'code')->all();
        $vat = VatCode::query()->get(['code', 'country_iso'])->map(static fn (VatCode $code): string => strtoupper($code->getAttribute('country_iso').':'.$code->getAttribute('code')))->flip()->all();
        $existing = Product::query()->whereIn('sku', array_map(static fn (array $row): string => (string) $row['cells']['parent_sku'], array_filter($rows, static fn (array $row): bool => ($row['cells']['parent_sku'] ?? null) !== null)))->pluck('sku')->map(static fn (string $sku): string => strtoupper($sku))->flip()->all();
        $kept = [];

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $problems = [];

            if (($cells['stock_uom'] ?? null) !== null && ! isset($units[$cells['stock_uom']])) {
                $problems[] = ['stock_uom', 'unit_not_found', ['code' => $cells['stock_uom']]];
            }

            foreach ((array) $this->parsePackagings($cells['packagings'] ?? null) as $level) {
                if (! isset($units[$level['uom']])) {
                    $problems[] = ['packagings', 'unit_not_found', ['code' => $level['uom']]];
                }
            }

            foreach (['it' => 'vat_code_it', 'ch' => 'vat_code_ch'] as $country => $field) {
                if (($cells[$field] ?? null) !== null && ! isset($vat[strtoupper($country.':'.$cells[$field])])) {
                    $problems[] = [$field, 'vat_code_country_mismatch', ['country' => strtoupper($country)]];
                }
            }

            $parent = $cells['parent_sku'] ?? null;

            if ($parent !== null && ! isset($fileSkus[strtoupper($parent)]) && ! isset($existing[strtoupper($parent)])) {
                $problems[] = ['parent_sku', 'parent_not_found', ['sku' => $parent]];
            }

            if ($parent !== null && ($cells['kit_type'] ?? null) !== null) {
                $problems[] = ['kit_type', 'kit_cannot_be_variant', []];
            }

            if (($cells['kit_components'] ?? null) !== null && ($cells['kit_type'] ?? null) === null) {
                $problems[] = ['kit_components', 'not_a_kit', []];
            }

            foreach ((array) $this->parseKit($cells['kit_components'] ?? null) as $component) {
                if (! isset($fileSkus[strtoupper($component['sku'])]) && ! Product::query()->where('sku', $component['sku'])->exists()) {
                    $problems[] = ['kit_components', 'component_not_found', ['component' => $component['sku']]];
                }
            }

            foreach ($problems as [$field, $code, $params]) {
                $errors[] = ['line' => $row['line'], 'sku' => $cells['sku'] ?? null, 'field' => $field, 'message' => (string) __('product_catalog.errors.'.$code, $params)];
            }

            if ($problems === []) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * Writes the valid rows: parents and simple products, then variants, then packagings and kit components.
     *
     * @param  list<array{line: int, cells: array<string, string|null>}>  $rows
     * @return array{created: int, updated: int, errors: list<array{line: int, sku: string|null, field: string, message: string}>}
     */
    private function apply(array $rows, bool $isolate): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];
        $saved = [];
        $ordered = [...array_filter($rows, static fn (array $row): bool => ($row['cells']['parent_sku'] ?? null) === null), ...array_filter($rows, static fn (array $row): bool => ($row['cells']['parent_sku'] ?? null) !== null)];

        foreach ($ordered as $row) {
            $write = function () use ($row, &$created, &$updated, &$saved): void {
                [$product, $isNew] = $this->upsert($row['cells']);
                $saved[$row['line']] = $product;
                $isNew ? $created++ : $updated++;
            };

            try {
                $isolate ? DB::transaction($write) : $write();
            } catch (CatalogException $exception) {
                $errors[] = $this->failRow($isolate, $row, $exception);
            }
        }

        foreach ($ordered as $row) {
            $product = $saved[$row['line']] ?? null;

            if ($product === null) {
                continue;
            }

            $secondPass = function () use ($row, $product): void {
                $this->structures($product, $row['cells']);
            };

            try {
                $isolate ? DB::transaction($secondPass) : $secondPass();
            } catch (CatalogException $exception) {
                $errors[] = $this->failRow($isolate, $row, $exception);
            }
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * The error of a row that could not be saved. An atomic import (not isolated) stops there and rolls back.
     *
     * @param  array{line: int, cells: array<string, string|null>}  $row
     * @return array{line: int, sku: string|null, field: string, message: string}
     *
     * @throws ImportAborted
     */
    private function failRow(bool $isolate, array $row, CatalogException $exception): array
    {
        $error = $this->rowError($row, $exception);

        if (! $isolate) {
            throw new ImportAborted([$error]);
        }

        return $error;
    }

    /**
     * @param  array{line: int, cells: array<string, string|null>}  $row
     * @return array{line: int, sku: string|null, field: string, message: string}
     */
    private function rowError(array $row, CatalogException $exception): array
    {
        return [
            'line' => $row['line'],
            'sku' => $row['cells']['sku'] ?? null,
            'field' => 'row',
            'message' => (string) __('product_catalog.errors.'.$exception->errorCode(), array_filter($exception->params(), 'is_scalar')),
        ];
    }

    /**
     * @param  array<string, string|null>  $cells
     * @return array{0: Product, 1: bool}
     */
    private function upsert(array $cells): array
    {
        $cells = $this->typed($cells);
        $existing = Product::withTrashed()->where('sku', $cells['sku'])->first();

        if ($existing?->trashed()) {
            throw new CatalogException('sku_taken', 422, ['sku' => $cells['sku']]);
        }

        $parent = isset($cells['parent_sku']) ? Product::query()->where('sku', $cells['parent_sku'])->firstOrFail() : null;
        $data = $this->mapData($cells, $parent, $existing);

        if ($existing !== null) {
            return [$this->products->update($existing, $data), false];
        }

        return [$this->products->create($data, $parent), true];
    }

    /**
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    private function mapData(array $cells, ?Product $parent, ?Product $existing): array
    {
        $data = [];

        foreach (['sku', 'name', 'description', 'barcode', 'tax_rate', 'country_of_origin', 'net_weight_kg', 'gross_weight_kg', 'cost_price', 'sale_price'] as $field) {
            if (($cells[$field] ?? null) !== null) {
                $data[$field] = $cells[$field];
            }
        }

        foreach (['product_type', 'kit_type', 'price_delta', 'price_delta_type', 'cost_delta', 'cost_delta_type', 'track_inventory', 'is_active'] as $field) {
            if (($cells[$field] ?? null) !== null) {
                $data[$field] = $cells[$field];
            }
        }

        if (($cells['tares_code'] ?? null) !== null) {
            $data['swiss_hs_code'] = $cells['tares_code'];
        }

        if (($cells['taric_code'] ?? null) !== null) {
            $data['taric_code'] = $cells['taric_code'];
        }

        if (($cells['stock_uom'] ?? null) !== null) {
            $data['unit_of_measure_id'] = UnitOfMeasure::query()->where('code', $cells['stock_uom'])->value('id');
        }

        foreach (['it' => 'vat_code_it', 'ch' => 'vat_code_ch'] as $country => $field) {
            if (($cells[$field] ?? null) !== null) {
                $data[$field.'_id'] = VatCode::query()->where('country_iso', strtoupper($country))->where('code', $cells[$field])->value('id');
            }
        }

        if ($parent !== null && $existing === null) {
            foreach (['cost_price' => 'cost', 'sale_price' => 'price'] as $field => $kind) {
                if (($cells[$field] ?? null) === null) {
                    $data[$field] = VariantPricing::resolve($parent->{$field}, [['amount' => $cells[$kind.'_delta'] ?? null, 'type' => $cells[$kind.'_delta_type'] ?? null]]);
                } else {
                    $data[$kind.'_overridden'] = true;
                }
            }

            foreach (['description', 'tax_rate', 'product_type', 'category_id', 'brand_id', 'unit_of_measure_id', 'unit_measure', 'min_stock'] as $field) {
                $data[$field] ??= $parent->{$field};
            }

            $data['track_inventory'] ??= $parent->track_inventory;
            $data['attributes'] = array_map('strval', (array) $this->parseAttributes((string) ($cells['attributes'] ?? '')));
        } elseif ($parent !== null && ($cells['attributes'] ?? null) !== null) {
            $data['attributes'] = array_map('strval', (array) $this->parseAttributes((string) $cells['attributes']));
        }

        return $data;
    }

    /**
     * The packaging hierarchy, the kit bill of materials and the links of a variant to the attribute values it carries.
     *
     * @param  array<string, string|null>  $rawCells
     */
    private function structures(Product $product, array $rawCells): void
    {
        $cells = $this->typed($rawCells);
        $levels = $this->parsePackagings($cells['packagings'] ?? null);

        if ($levels !== null && $levels !== []) {
            $units = UnitOfMeasure::query()->pluck('id', 'code')->all();
            $this->packagings->sync($product, array_map(static fn (array $level): array => [
                'code' => $level['code'],
                'uom_id' => $units[$level['uom']],
                'units_per_parent' => $level['units'],
                'parent_code' => $level['parent'],
                'role' => $level['role'],
                'gtin' => $level['gtin'],
            ], $levels));
        }

        $components = $this->parseKit($cells['kit_components'] ?? null);

        if ($components !== null && $components !== []) {
            $ids = Product::query()->whereIn('sku', array_column($components, 'sku'))->pluck('id', 'sku')->all();
            $this->kits->setComponents($product->refresh(), array_map(static fn (array $component): array => [
                'component_id' => $ids[$component['sku']],
                'quantity' => $component['quantity'],
                'is_optional' => $component['optional'],
            ], $components));
        }

        if ($product->is_variant) {
            $this->linkValues($product);
        }
    }

    /**
     * Links a variant to the catalog attribute values its `attributes` JSON names (matched by English name and label), when the
     * catalog knows them; unknown attributes stay as plain text on the variant.
     */
    private function linkValues(Product $variant): void
    {
        $attributes = CatalogAttribute::query()->with('values')->get();
        CatalogVariantValue::query()->where('variant_id', $variant->id)->forceDelete();

        foreach ($variant->optionAttributes() as $name => $label) {
            $attribute = $attributes->first(static fn (CatalogAttribute $candidate): bool => strcasecmp($candidate->nameIn('en'), (string) $name) === 0 || strcasecmp($candidate->code, (string) $name) === 0);
            $value = $attribute?->values->first(static fn (CatalogAttributeValue $candidate): bool => strcasecmp($candidate->labelIn('en'), $label) === 0 || strcasecmp($candidate->code, $label) === 0);

            if ($attribute !== null && $value !== null) {
                CatalogVariantValue::create(['variant_id' => $variant->id, 'attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]);
            }
        }
    }

    /**
     * Cells with booleans and the empty strings normalised.
     *
     * @param  array<string, string|null>  $cells
     * @return array<string, mixed>
     */
    private function typed(array $cells): array
    {
        $typed = $cells;

        foreach (['track_inventory', 'is_active'] as $field) {
            if (($cells[$field] ?? null) !== null) {
                $typed[$field] = match (strtolower((string) $cells[$field])) {
                    '1', 'true', 'yes', 'y', 'si', 'sì', 'oui', 'ja' => true,
                    '0', 'false', 'no', 'n', 'non', 'nein' => false,
                    default => $cells[$field],
                };
            }
        }

        foreach (['product_type', 'kit_type', 'price_delta_type', 'cost_delta_type'] as $field) {
            if (($cells[$field] ?? null) !== null) {
                $typed[$field] = strtolower((string) $cells[$field]);
            }
        }

        if (($cells['country_of_origin'] ?? null) !== null) {
            $typed['country_of_origin'] = strtoupper((string) $cells['country_of_origin']);
        }

        foreach (['tares_code', 'taric_code'] as $field) {
            if (($cells[$field] ?? null) !== null) {
                $typed[$field] = preg_replace('/[\s.]/', '', (string) $cells[$field]);
            }
        }

        return $typed;
    }

    /**
     * @return list<array{code: string, uom: string, units: string, parent: string|null, role: string, gtin: string|null}>|null null when malformed
     */
    private function parsePackagings(?string $text): ?array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $levels = [];

        foreach (array_filter(array_map('trim', explode(';', $text))) as $entry) {
            $parts = array_map('trim', explode('|', $entry));

            if (count($parts) < 3 || $parts[0] === '' || $parts[1] === '' || ! is_numeric($parts[2]) || (float) $parts[2] <= 0) {
                return null;
            }

            $role = strtolower($parts[4] ?? 'logistic') ?: 'logistic';

            if (PackagingRole::tryFrom($role) === null) {
                return null;
            }

            $levels[] = [
                'code' => strtoupper($parts[0]),
                'uom' => $parts[1],
                'units' => $parts[2],
                'parent' => ($parts[3] ?? '') !== '' ? strtoupper($parts[3]) : null,
                'role' => $role,
                'gtin' => ($parts[5] ?? '') !== '' ? $parts[5] : null,
            ];
        }

        return $levels;
    }

    /**
     * @return list<array{sku: string, quantity: string, optional: bool}>|null null when malformed
     */
    private function parseKit(?string $text): ?array
    {
        if ($text === null || $text === '') {
            return [];
        }

        $components = [];

        foreach (array_filter(array_map('trim', explode(';', $text))) as $entry) {
            $parts = array_map('trim', explode(':', $entry));

            if (count($parts) < 2 || $parts[0] === '' || ! is_numeric($parts[1]) || (float) $parts[1] <= 0) {
                return null;
            }

            $components[] = ['sku' => $parts[0], 'quantity' => $parts[1], 'optional' => strtolower($parts[2] ?? '') === 'optional'];
        }

        return $components;
    }

    /**
     * @return array<string, string>|null null when malformed
     */
    private function parseAttributes(string $text): ?array
    {
        $attributes = [];

        foreach (array_filter(array_map('trim', explode('|', $text))) as $entry) {
            $pair = array_map('trim', explode('=', $entry, 2));

            if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '') {
                return null;
            }

            $attributes[$pair[0]] = $pair[1];
        }

        return $attributes;
    }

    /**
     * @param  list<array{line: int, cells: array<string, string|null>}>  $rows
     * @return array{0: int, 1: int}
     */
    private function countOutcomes(array $rows): array
    {
        $existing = Product::withTrashed()->whereIn('sku', array_map(static fn (array $row): string => (string) $row['cells']['sku'], $rows))->pluck('sku')->all();
        $updated = count($existing);

        return [count($rows) - $updated, $updated];
    }

    /**
     * @param  list<array{line: int, sku: string|null, field: string, message: string}>  $errors
     */
    private function failedRows(array $errors): int
    {
        return count(array_unique(array_column($errors, 'line')));
    }
}
