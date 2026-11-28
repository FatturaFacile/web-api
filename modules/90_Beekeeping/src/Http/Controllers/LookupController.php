<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Beekeeping\Domain\Enums\ApiaryType;
use Modules\Beekeeping\Domain\Enums\BotanicalOrigin;
use Modules\Beekeeping\Domain\Enums\Country;
use Modules\Beekeeping\Domain\Enums\FeedingType;
use Modules\Beekeeping\Domain\Enums\GeneticLine;
use Modules\Beekeeping\Domain\Enums\HiveOrigin;
use Modules\Beekeeping\Domain\Enums\HiveStatus;
use Modules\Beekeeping\Domain\Enums\HiveType;
use Modules\Beekeeping\Domain\Enums\MovementKind;
use Modules\Beekeeping\Domain\Enums\MovementStatus;
use Modules\Beekeeping\Domain\Enums\MovementSubject;
use Modules\Beekeeping\Domain\Enums\ProductKind;
use Modules\Beekeeping\Domain\Enums\QueenStatus;
use Modules\Beekeeping\Domain\Enums\SaleChannel;
use Modules\Beekeeping\Domain\Enums\TaxRegime;
use Modules\Beekeeping\Domain\Enums\TreatmentProduct;
use Modules\Beekeeping\Domain\Enums\VarroaCountType;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use OpenApi\Attributes as OA;

/**
 * Reference data of the module: enumerations, statutory parameters and the default withdrawal periods of the treatment products.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    #[OA\Get(path: '/api/v1/beekeeping/lookups', operationId: 'beekeepingLookups', summary: 'Enumerations and statutory parameters', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function index(): JsonResponse
    {
        $this->authorizeRead();

        return $this->successResponse([
            'countries' => Country::values(),
            'apiary_types' => ApiaryType::values(),
            'hive_types' => HiveType::values(),
            'hive_origins' => HiveOrigin::values(),
            'hive_statuses' => HiveStatus::values(),
            'queen_statuses' => QueenStatus::values(),
            'genetic_lines' => GeneticLine::values(),
            'botanical_origins' => BotanicalOrigin::values(),
            'treatment_products' => array_map(static fn (TreatmentProduct $product): array => [
                'value' => $product->value,
                'substance' => config('beekeeping.treatment_products.'.$product->value.'.substance'),
                'withdrawal_days' => config('beekeeping.treatment_products.'.$product->value.'.withdrawal_days'),
            ], TreatmentProduct::cases()),
            'movement_kinds' => MovementKind::values(),
            'movement_subjects' => MovementSubject::values(),
            'movement_statuses' => MovementStatus::values(),
            'product_kinds' => ProductKind::values(),
            'sale_channels' => SaleChannel::values(),
            'tax_regimes' => TaxRegime::values(),
            'feeding_types' => FeedingType::values(),
            'varroa_count_types' => VarroaCountType::values(),
            'queen_colors' => config('beekeeping.queen_colors'),
            'jar_formats_g' => config('beekeeping.jar_formats_g'),
            'parameters' => [
                'foraging_radius_m' => config('beekeeping.foraging_radius_m'),
                'moisture_warning' => config('beekeeping.quality.moisture_warning'),
                'moisture_limit' => config('beekeeping.quality.moisture_limit'),
                'hmf_warning' => config('beekeeping.quality.hmf_warning'),
                'hmf_limit' => config('beekeeping.quality.hmf_limit'),
                'best_before_months' => config('beekeeping.best_before_months'),
                'census' => config('beekeeping.census'),
                'ch_reduced_rate' => config('beekeeping.tax.CH.reduced'),
                'ch_standard_rate' => config('beekeeping.tax.CH.standard'),
            ],
        ]);
    }
}
