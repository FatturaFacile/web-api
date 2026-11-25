<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Domain\Support\DiscountMatrixEvaluator;
use Modules\CorporateConventions\Domain\ValueObjects\CartLine;
use Modules\CorporateConventions\Domain\ValueObjects\DiscountRuleSpec;
use Modules\CorporateConventions\Domain\ValueObjects\PricingResult;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;

/**
 * Prices a cart under a convention: resolves the catalogue data of every product (category chain, price-list prices, cost) and
 * runs the domain evaluator with the balance of the beneficiary.
 */
final class ConventionPricingService
{
    public function __construct(
        private readonly DiscountMatrixEvaluator $evaluator,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function price(CorporatePartnerConvention $convention, ConventionBeneficiaryEnrollment $enrollment, array $lines, ?string $currency = null): PricingResult
    {
        if ($enrollment->convention_id !== $convention->id) {
            throw new ConventionException('enrollment_convention_mismatch', 422);
        }

        $this->enrollments->assertUsable($enrollment);

        if ($currency !== null && strtoupper($currency) !== $convention->currency) {
            throw new ConventionException('cart_currency_mismatch', 422, ['expected' => $convention->currency]);
        }

        if ($lines === []) {
            throw new ConventionException('cart_empty', 422);
        }

        $convention->loadMissing('discountRules');
        $rules = $convention->ruleSpecs();

        return $this->evaluator->evaluate(
            $this->cartLines($lines, $rules),
            $rules,
            $convention->terms(),
            $enrollment->balanceFor((int) now()->year),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<DiscountRuleSpec>  $rules
     * @return list<CartLine>
     */
    private function cartLines(array $lines, array $rules): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(static fn (array $line): ?string => $line['product_id'] ?? null, $lines))));
        $products = $productIds === [] ? [] : DB::table('products')->whereIn('id', $productIds)->whereNull('deleted_at')
            ->where('tenant_id', app(TenantContext::class)->getTenantId())
            ->get(['id', 'sale_price', 'cost_price', 'category_id'])->keyBy('id')->all();
        $parents = DB::table('product_categories')->where('tenant_id', app(TenantContext::class)->getTenantId())->pluck('parent_id', 'id')->all();
        $listIds = array_values(array_unique(array_filter(array_map(static fn ($rule): ?string => $rule->type === RuleType::PriceList ? $rule->targetPriceListId : null, $rules))));

        $result = [];

        foreach ($lines as $index => $line) {
            $product = isset($line['product_id']) ? ($products[$line['product_id']] ?? null) : null;

            if (isset($line['product_id']) && $product === null) {
                throw new ConventionException('cart_product_unknown', 422, ['line' => $index + 1]);
            }

            $unitPrice = $line['unit_price'] ?? $product->sale_price ?? null;

            if ($unitPrice === null) {
                throw new ConventionException('cart_line_price_missing', 422, ['line' => $index + 1]);
            }

            $categoryId = $line['category_id'] ?? $product->category_id ?? null;
            $quantity = (string) $line['quantity'];

            $result[] = new CartLine(
                (string) ($line['line_id'] ?? $index + 1),
                $quantity,
                (string) $unitPrice,
                (string) ($line['unit_cost'] ?? $product->cost_price ?? '0'),
                $categoryId,
                $this->categoryPath($categoryId, $parents),
                (bool) ($line['is_promotional'] ?? false),
                (string) ($line['existing_discount_percent'] ?? '0'),
                isset($line['product_id']) ? $this->priceListPrices((string) $line['product_id'], $quantity, $listIds) : [],
            );
        }

        return $result;
    }

    /**
     * @param  array<string, string|null>  $parents
     * @return list<string>
     */
    private function categoryPath(?string $categoryId, array $parents): array
    {
        $path = [];

        while ($categoryId !== null && ! in_array($categoryId, $path, true)) {
            $path[] = $categoryId;
            $categoryId = $parents[$categoryId] ?? null;
        }

        return $path;
    }

    /**
     * Unit price of the product in each price list the matrix refers to, honouring the quantity break.
     *
     * @param  list<string>  $listIds
     * @return array<string, string>
     */
    private function priceListPrices(string $productId, string $quantity, array $listIds): array
    {
        if ($listIds === []) {
            return [];
        }

        $rows = DB::table('price_list_items')->where('product_id', $productId)->whereIn('price_list_id', $listIds)
            ->where('min_quantity', '<=', $quantity)->orderByDesc('min_quantity')->get(['price_list_id', 'price']);
        $prices = [];

        foreach ($rows as $row) {
            $prices[(string) $row->price_list_id] ??= (string) $row->price;
        }

        return $prices;
    }
}
