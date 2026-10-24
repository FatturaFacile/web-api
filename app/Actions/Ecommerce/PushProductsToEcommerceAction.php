<?php

declare(strict_types=1);

namespace App\Actions\Ecommerce;

use App\Exceptions\EcommerceException;
use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\Product;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\Inventory\StockService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the catalogue on a channel: simple products and variable products with their variants. Products that are
 * already mapped on the channel are skipped. The remote ids are stored in `ecommerce_mappings`, with the parent and
 * platform specific ids (inventory item, stock record) of every variant in `extra_data`.
 */
class PushProductsToEcommerceAction
{
    public function __construct(
        private readonly EcommerceManager $manager,
        private readonly StockService $stock,
    ) {}

    /**
     * @return array{created: int, variants: int, skipped: int}
     *
     * @throws EcommerceException
     */
    public function execute(EcommerceChannel $channel): array
    {
        $mapped = EcommerceMapping::query()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('entity_type', 'product')
            ->pluck('local_id')
            ->all();

        $roots = Product::query()->withoutVariants()->where('is_active', true)->orderBy('sku')->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sku')])->get();
        $pending = $roots->reject(fn (Product $product): bool => in_array($product->id, $mapped, true));
        $summary = ['created' => 0, 'variants' => 0, 'skipped' => $roots->count() - $pending->count()];

        if ($pending->isEmpty()) {
            return $summary;
        }

        $results = $this->manager->driver($channel)->pushProducts($pending->map(fn (Product $product): array => $this->payload($product))->values()->all());
        $byId = $pending->keyBy('id');

        DB::transaction(function () use ($results, $byId, $channel, &$summary): void {
            foreach ($results as $result) {
                $product = $byId->get($result['local_id']);

                if ($product === null) {
                    continue;
                }

                $this->map($channel, 'product', $product, $result['remote_id'], Arr::except($result, ['local_id', 'remote_id', 'variants']));
                $summary['created']++;

                foreach ($result['variants'] ?? [] as $variantResult) {
                    $variant = $product->variants->firstWhere('id', $variantResult['local_id']);

                    if ($variant !== null) {
                        $this->map($channel, 'product_variant', $variant, $variantResult['remote_id'], Arr::except($variantResult, ['local_id', 'remote_id']) + ['attributes' => $variant->optionAttributes()]);
                        $summary['variants']++;
                    }
                }
            }
        });

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        $variants = $product->variants;

        return [
            'local_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => (string) $product->description,
            'price' => (float) $product->sale_price,
            'stock' => $variants->isEmpty() ? $this->stock->quantityOf($product->id) : 0.0,
            'type' => $variants->isEmpty() ? 'simple' : 'variable',
            'variants' => $variants->map(fn (Product $variant): array => [
                'local_id' => $variant->id,
                'sku' => $variant->sku,
                'price' => (float) $variant->sale_price,
                'stock' => $this->stock->quantityOf($variant->id),
                'attributes' => $variant->optionAttributes(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function map(EcommerceChannel $channel, string $entityType, Product $product, string $remoteId, array $extra): void
    {
        EcommerceMapping::query()->updateOrCreate(
            ['tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id, 'entity_type' => $entityType, 'remote_id' => $remoteId],
            ['local_id' => $product->id, 'remote_sku' => $product->sku, 'extra_data' => $extra]
        );
    }
}
