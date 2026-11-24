<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Application\Queries;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * ERP catalog lookup for the "Drag Product from ERP Catalog" block of the e-mail designer: name, description, list
 * price and image of the product, ready to be imported into the block.
 */
final class SearchCatalogProductsHandler
{
    /**
     * @return list<array{id: string, sku: string, name: string, description: string|null, price: float, currency: string, image_url: string|null}>
     */
    public function handle(string $tenantId, string $term, int $limit = 20): array
    {
        $currency = (string) (Tenant::query()->whereKey($tenantId)->value('default_currency') ?? 'CHF');
        $needle = '%'.mb_strtolower(trim($term)).'%';

        $rows = DB::table('products')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)
            ->when(trim($term) !== '', static function ($q) use ($needle): void {
                $q->where(static function ($w) use ($needle): void {
                    $w->whereRaw('LOWER(name) LIKE ?', [$needle])->orWhereRaw('LOWER(sku) LIKE ?', [$needle]);
                });
            })
            ->orderBy('name')->limit(max(1, min($limit, 50)))
            ->get(['id', 'sku', 'name', 'description', 'sale_price', 'attributes']);

        return $rows->map(static function (object $row) use ($currency): array {
            $attributes = is_string($row->attributes) ? (array) json_decode($row->attributes, true) : [];

            return [
                'id' => (string) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'description' => $row->description === null ? null : (string) $row->description,
                'price' => (float) $row->sale_price,
                'currency' => $currency,
                'image_url' => isset($attributes['image_url']) ? (string) $attributes['image_url'] : null,
            ];
        })->all();
    }
}
