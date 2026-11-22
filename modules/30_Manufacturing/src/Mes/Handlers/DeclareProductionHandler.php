<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Events\MesProductionDeclaredEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Mes\Commands\DeclareProductionCommand;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgMesProductionDeclaration;
use Modules\Manufacturing\Models\MfgMesScrapDeclaration;
use Modules\Manufacturing\Support\Money;

/**
 * Records declared good and scrapped pieces of a phase; scrapped pieces need their defect reason.
 */
final class DeclareProductionHandler extends PhaseCommandHandler
{
    /**
     * Handles the command and returns the phase.
     */
    public function handle(DeclareProductionCommand $command): MfgMesPhase
    {
        $good = Money::qty($command->goodQuantity);
        $scrap = Money::qty($command->scrapQuantity);
        if (Money::isNegative($good) || Money::isNegative($scrap)) {
            throw new ManufacturingOperationException('Quantities cannot be negative.', 'good_quantity');
        }
        if (! Money::isPositive(Money::add($good, $scrap))) {
            throw new ManufacturingOperationException('Declare at least one good or scrapped piece.', 'good_quantity');
        }
        if (Money::isPositive($scrap) && $command->defectReason === null) {
            throw new ManufacturingOperationException('Scrapped pieces need a defect reason.', 'defect_reason');
        }

        return $this->execute($command, function () use ($command, $good, $scrap): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::DeclareProduction, $phase);
            $at = $this->effectiveAt($command, $phase);

            $declaration = MfgMesProductionDeclaration::create([
                'tenant_id' => $command->tenantId,
                'phase_id' => $phase->id,
                'operator_id' => $phase->operator_id,
                'good_quantity' => $good,
                'scrap_quantity' => $scrap,
                'idempotency_key' => $command->idempotencyKey,
                'declared_at' => $at,
            ]);
            if (Money::isPositive($scrap)) {
                MfgMesScrapDeclaration::create([
                    'tenant_id' => $command->tenantId,
                    'phase_id' => $phase->id,
                    'production_declaration_id' => $declaration->id,
                    'quantity' => $scrap,
                    'defect_reason' => $command->defectReason,
                    'note' => $command->note,
                ]);
            }

            $this->save($phase);
            $this->dispatcher->publishAndDispatch(new MesProductionDeclaredEvent(
                phaseId: (string) $phase->id,
                declarationId: (string) $declaration->id,
                productionOrderId: (string) $phase->production_order_id,
                goodQuantity: $good,
                scrapQuantity: $scrap,
                defectReason: $command->defectReason?->value,
                tenantId: $command->tenantId,
            ));

            return $phase;
        });
    }
}
