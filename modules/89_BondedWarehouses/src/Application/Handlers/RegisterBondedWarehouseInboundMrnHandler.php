<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\BondedWarehouses\Application\Commands\RegisterBondedWarehouseInboundMrnCommand;
use Modules\BondedWarehouses\Application\Services\CguGuaranteeService;
use Modules\BondedWarehouses\Domain\Enums\CguReferenceType;
use Modules\BondedWarehouses\Domain\Enums\CustomsStatus;
use Modules\BondedWarehouses\Domain\Enums\DeclarationType;
use Modules\BondedWarehouses\Domain\Enums\RegimeType;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Domain\Support\CustomsCodes;
use Modules\BondedWarehouses\Events\BondedMrnLotRegistered;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\Inventory\Models\StockLot;

/**
 * Registers the entry of goods under an MRN: checks the authorization, the MRN and the TARIC code, engages the suspended duty and VAT on
 * the comprehensive guarantee (refused when the ceiling would be exceeded), creates the accounting lot and flags the WMS lot as non-Union goods.
 */
final class RegisterBondedWarehouseInboundMrnHandler
{
    public function __construct(private readonly CguGuaranteeService $guarantee) {}

    /**
     * @throws BondedWarehouseException authorization_not_found, authorization_inactive, regime_not_customs_warehouse, declaration_type_not_allowed,
     *                                  invalid_mrn, invalid_taric, mrn_already_registered, cgu_ceiling_exceeded
     */
    public function handle(RegisterBondedWarehouseInboundMrnCommand $command): BondedMrnLot
    {
        $mrn = CustomsCodes::normalizeMrn($command->mrnNumber);

        if (! CustomsCodes::isValidMrn($mrn)) {
            throw BondedWarehouseException::because('invalid_mrn');
        }

        if (! CustomsCodes::isValidTaric($command->taricHsCode)) {
            throw BondedWarehouseException::because('invalid_taric');
        }

        if ($command->quantity <= 0.0) {
            throw BondedWarehouseException::because('quantity_must_be_positive');
        }

        return DB::transaction(function () use ($command, $mrn): BondedMrnLot {
            $authorization = CustomsWarehouseAuthorization::query()->find($command->authorizationId)
                ?? throw BondedWarehouseException::because('authorization_not_found', [], 404);

            $this->assertAuthorization($authorization, $command, Carbon::parse($command->entryDate));

            if (BondedMrnLot::query()->where('mrn_number', $mrn)->where('mrn_item_number', $command->mrnItemNumber)->exists()) {
                throw BondedWarehouseException::because('mrn_already_registered', ['mrn' => $mrn, 'item' => $command->mrnItemNumber], 409);
            }

            $duty = $command->suspendedDutyAmount ?? round($command->customsStatisticalValue * $command->dutyRatePercent / 100, 2);
            $vat = $command->suspendedVatAmount ?? round(($command->customsStatisticalValue + $duty) * $command->vatRatePercent / 100, 2);

            $lot = BondedMrnLot::query()->create([
                'tenant_id' => $authorization->tenant_id,
                'authorization_id' => $authorization->id,
                'mrn_number' => $mrn,
                'mrn_item_number' => $command->mrnItemNumber,
                'declaration_type' => $command->declarationType,
                'entry_date' => $command->entryDate,
                'product_id' => $command->productId,
                'wms_lot_id' => $command->wmsLotId,
                'taric_hs_code' => $command->taricHsCode,
                'country_of_origin' => $command->countryOfOrigin,
                'preferential_origin' => $command->preferentialOrigin,
                'preferential_agreement_code' => $command->preferentialOrigin ? $command->preferentialAgreementCode : null,
                'initial_quantity' => $command->quantity,
                'remaining_quantity' => $command->quantity,
                'unit_of_measure' => $command->unitOfMeasure,
                'initial_net_weight_kg' => $command->netWeightKg,
                'remaining_net_weight_kg' => $command->netWeightKg,
                'initial_gross_weight_kg' => $command->grossWeightKg,
                'remaining_gross_weight_kg' => $command->grossWeightKg,
                'customs_statistical_value' => $command->customsStatisticalValue,
                'duty_rate_percent' => $command->dutyRatePercent,
                'vat_rate_percent' => $command->vatRatePercent,
                'suspended_duty_amount' => $duty,
                'suspended_vat_amount' => $vat,
                'cgu_engaged_amount' => round($duty + $vat, 2),
                'customs_status' => CustomsStatus::NonUnionSuspended,
            ]);

            $this->guarantee->engage((string) $authorization->id, CguReferenceType::MRNInbound, (string) $lot->id, $duty, $vat);

            if ($command->wmsLotId !== null) {
                StockLot::query()->whereKey($command->wmsLotId)->update(['customs_status' => CustomsStatus::NonUnionSuspended->value]);
            }

            event(new BondedMrnLotRegistered((string) $lot->id, (string) $authorization->id, $mrn, round($duty + $vat, 2)));

            return $lot->refresh();
        });
    }

    private function assertAuthorization(CustomsWarehouseAuthorization $authorization, RegisterBondedWarehouseInboundMrnCommand $command, Carbon $entry): void
    {
        if (! $authorization->isUsableOn($entry)) {
            throw BondedWarehouseException::because('authorization_inactive', ['code' => $authorization->authorization_code]);
        }

        if ($authorization->regime_type === RegimeType::VatDepotArt50Bis) {
            throw BondedWarehouseException::because('regime_not_customs_warehouse');
        }

        $allowed = match ($command->declarationType) {
            DeclarationType::IM7 => $authorization->regime_type === RegimeType::CDUPrivate7100,
            DeclarationType::PassarImport => in_array($authorization->regime_type, [RegimeType::SwissOZL, RegimeType::SwissZollfreilagerZE], true),
            DeclarationType::T1 => true,
        };

        if (! $allowed) {
            throw BondedWarehouseException::because('declaration_type_not_allowed', ['declaration' => $command->declarationType->value, 'regime' => $authorization->regime_type->value]);
        }
    }
}
