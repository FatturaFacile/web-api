<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\VanSalesAndFieldForce\Domain\Enums\CollectionMethod;
use Modules\VanSalesAndFieldForce\Domain\Enums\LoadManifestStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\SettlementStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitMode;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitOutcomeStatus;
use Modules\VanSalesAndFieldForce\Http\Controllers\Concerns\InteractsWithVanSelling;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use OpenApi\Attributes as OA;

/**
 * Exposes all backend-seeded UI copy, labels, status names, reasons, printer profiles,
 * empties catalog, and regulatory notices for Mod 233 so the Angular frontend needs zero
 * hardcoded i18n keys or text additions.
 */
final class VanSellingConfigController extends Controller
{
    use InteractsWithVanSelling;

    #[OA\Get(
        path: '/api/v1/van-selling/config',
        operationId: 'getVanSellingConfig',
        summary: 'Get seeded Van Selling & Field Sales configuration, UI copy dictionary, and lookups',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Van Selling configuration')]
    )]
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', VanWarehouse::class);

        $uiCopy = __('van_selling.ui');
        $statuses = __('van_selling.statuses');
        $reasons = __('van_selling.no_sale_reasons');
        $docTypes = __('van_selling.document_types');
        $paymentMethods = __('van_selling.payment_methods');
        $printerProfiles = __('van_selling.printer_profiles');
        $regulatory = __('van_selling.regulatory');

        return response()->json([
            'data' => [
                'ui_copy' => is_array($uiCopy) ? $uiCopy : [],
                'regulatory' => is_array($regulatory) ? $regulatory : [],
                'van_statuses' => $this->mapEnumOptions(VanWarehouseStatus::values(), is_array($statuses) ? $statuses : []),
                'manifest_statuses' => $this->mapEnumOptions(LoadManifestStatus::values(), is_array($statuses) ? $statuses : []),
                'visit_modes' => $this->mapEnumOptions(VisitMode::values(), is_array($statuses) ? $statuses : []),
                'visit_outcomes' => $this->mapEnumOptions(VisitOutcomeStatus::values(), is_array($statuses) ? $statuses : []),
                'settlement_statuses' => $this->mapEnumOptions(SettlementStatus::values(), is_array($statuses) ? $statuses : []),
                'document_types' => $this->mapEnumOptions(VanFiscalDocumentType::values(), is_array($docTypes) ? $docTypes : []),
                'payment_methods' => $this->mapEnumOptions(CollectionMethod::values(), is_array($paymentMethods) ? $paymentMethods : []),
                'printer_profiles' => $this->mapEnumOptions(ThermalPrinterProfile::values(), is_array($printerProfiles) ? $printerProfiles : []),
                'no_sale_reasons' => collect(is_array($reasons) ? $reasons : [])
                    ->map(static fn (string $label, string $code): array => ['code' => $code, 'label' => $label])
                    ->values()
                    ->all(),
                'empties_catalog' => [
                    ['item_code' => 'EMP-CASSA-151', 'item_name' => __('van_selling.empties.crate_mod_151'), 'unit_deposit' => 12.00],
                    ['item_code' => 'EMP-FUSTO-30L', 'item_name' => __('van_selling.empties.keg_30l'), 'unit_deposit' => 45.00],
                    ['item_code' => 'EMP-PALLET-EPAL', 'item_name' => __('van_selling.empties.epal_pallet'), 'unit_deposit' => 18.00],
                ],
                'sample_gs1_barcodes' => [
                    '(01)08001234567893(3103)002450(10)LOT-CH2611(17)261231',
                    '(01)08009876543210(3103)001820(10)LOT-IT2611(17)270115',
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     * @return list<array{code: string, label: string}>
     */
    private function mapEnumOptions(array $values, array $labels): array
    {
        return array_map(static fn (string $code): array => [
            'code' => $code,
            'label' => $labels[$code] ?? $code,
        ], $values);
    }
}
