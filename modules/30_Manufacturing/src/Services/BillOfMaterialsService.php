<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateEcoDTO;
use Modules\Manufacturing\Events\BOMCreatedEvent;
use Modules\Manufacturing\Events\BOMRevisedEvent;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Models\MfgItem;

final readonly class BillOfMaterialsService implements BillOfMaterialsServiceInterface
{
    public function __construct(
        private EventBusInterface $eventBus,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createBom(CreateBomDTO $dto): MfgBom
    {
        return DB::transaction(function () use ($dto): MfgBom {
            $bomNumber = 'BOM-'.strtoupper((string) Str::random(8));

            $bom = MfgBom::create([
                'tenant_id' => $dto->tenantId,
                'bom_number' => $bomNumber,
                'mfg_item_id' => $dto->mfgItemId,
                'name' => $dto->name,
                'revision' => $dto->revision,
                'version' => 1,
                'bom_type' => $dto->bomType,
                'status' => 'active',
                'base_quantity' => $dto->baseQuantity,
                'uom' => $dto->uom,
                'effective_from' => $dto->effectiveFrom ?? now()->toDateString(),
                'effective_to' => $dto->effectiveTo,
                'is_locked' => false,
            ]);

            $seq = 10;
            foreach ($dto->components as $c) {
                MfgBomComponent::create([
                    'tenant_id' => $dto->tenantId,
                    'bom_id' => $bom->id,
                    'component_item_id' => $c['component_item_id'],
                    'sequence' => $c['sequence'] ?? $seq,
                    'quantity_per' => $c['quantity_per'],
                    'uom' => $dto->uom,
                    'scrap_percentage' => $c['scrap_percentage'] ?? 0.0,
                    'yield_percentage' => $c['yield_percentage'] ?? 100.0,
                    'is_phantom' => $c['is_phantom'] ?? false,
                    'notes' => $c['notes'] ?? null,
                ]);
                $seq += 10;
            }

            // Link as default BOM if item has none
            $item = MfgItem::where('id', $dto->mfgItemId)->where('tenant_id', $dto->tenantId)->first();
            if ($item && $item->default_bom_id === null) {
                $item->update(['default_bom_id' => $bom->id]);
            }

            $this->auditLedger->record(
                'mfg.bom.created',
                $bom,
                null,
                ['bom_number' => $bom->bom_number, 'revision' => $bom->revision],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new BOMCreatedEvent(
                bomId: $bom->id,
                bomNumber: $bom->bom_number,
                mfgItemId: $bom->mfg_item_id,
                revision: $bom->revision,
                tenantId: $dto->tenantId
            ));

            return $bom->load('components.componentItem');
        });
    }

    public function explodeBom(string $bomId, float $parentQuantity = 1.0, int $level = 1): array
    {
        $bom = MfgBom::with(['components.componentItem.defaultBom.components.componentItem'])->findOrFail($bomId);
        $exploded = [];

        foreach ($bom->components as $component) {
            $scrapFactor = 1.0 + ((float) $component->scrap_percentage / 100.0);
            $yieldDivisor = (float) $component->yield_percentage > 0.0 ? ((float) $component->yield_percentage / 100.0) : 1.0;
            $effectiveQty = round(((float) $component->quantity_per * $parentQuantity * $scrapFactor) / $yieldDivisor, 4);

            $item = $component->componentItem;
            $isPhantom = (bool) $component->is_phantom || ($item && $item->isPhantom());
            $isSubassembly = $item && $item->isSubassembly();

            $exploded[] = [
                'component_item_id' => $component->component_item_id,
                'component_item_code' => $item ? $item->item_code : '',
                'component_name' => $item ? $item->name : '',
                'level' => $level,
                'quantity_per' => (float) $component->quantity_per,
                'scrap_percentage' => (float) $component->scrap_percentage,
                'effective_quantity' => $effectiveQty,
                'uom' => $component->uom,
                'is_phantom' => $isPhantom,
                'is_subassembly' => $isSubassembly,
            ];

            // If phantom or subassembly with sub-BOM, recursively explode
            if (($isPhantom || $isSubassembly) && $item && $item->default_bom_id && $item->default_bom_id !== $bomId) {
                $subExploded = $this->explodeBom($item->default_bom_id, $effectiveQty, $level + 1);
                foreach ($subExploded as $child) {
                    $exploded[] = $child;
                }
            }
        }

        return $exploded;
    }

    public function reviseBom(string $bomId, string $newRevision, string $reason): MfgBom
    {
        return DB::transaction(function () use ($bomId, $newRevision, $reason): MfgBom {
            $bom = MfgBom::findOrFail($bomId);
            $newVersion = $bom->version + 1;

            $bom->update([
                'revision' => $newRevision,
                'version' => $newVersion,
                'notes' => trim(($bom->notes ? $bom->notes."\n" : '')."Revision {$newRevision}: {$reason}"),
            ]);

            $this->auditLedger->record(
                'mfg.bom.revised',
                $bom,
                null,
                ['new_revision' => $newRevision, 'version' => $newVersion, 'reason' => $reason],
                '30_Manufacturing'
            );

            $this->eventBus->dispatch(new BOMRevisedEvent(
                bomId: $bom->id,
                bomNumber: $bom->bom_number,
                newRevision: $newRevision,
                version: $newVersion,
                tenantId: $bom->tenant_id
            ));

            return $bom;
        });
    }

    public function createEco(CreateEcoDTO $dto): MfgEngineeringChangeOrder
    {
        $ecoNumber = 'ECO-'.strtoupper((string) Str::random(8));

        $eco = MfgEngineeringChangeOrder::create([
            'tenant_id' => $dto->tenantId,
            'eco_number' => $ecoNumber,
            'title' => $dto->title,
            'reason' => $dto->reason,
            'description' => $dto->description,
            'change_type' => $dto->changeType,
            'status' => 'draft',
            'effective_date' => $dto->effectiveDate ?? now()->toDateString(),
            'requested_by' => $dto->requestedBy,
            'change_payload' => $dto->changePayload,
        ]);

        $this->auditLedger->record('mfg.eco.created', $eco, null, ['eco_number' => $eco->eco_number], '30_Manufacturing');

        return $eco;
    }

    public function approveEco(string $ecoId, string $approvedBy): MfgEngineeringChangeOrder
    {
        $eco = MfgEngineeringChangeOrder::findOrFail($ecoId);
        $eco->update([
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        $this->auditLedger->record('mfg.eco.approved', $eco, null, ['approved_by' => $approvedBy], '30_Manufacturing');

        return $eco;
    }

    public function implementEco(string $ecoId): MfgEngineeringChangeOrder
    {
        return DB::transaction(function () use ($ecoId): MfgEngineeringChangeOrder {
            $eco = MfgEngineeringChangeOrder::findOrFail($ecoId);
            if ($eco->status !== 'approved') {
                throw new InvalidArgumentException("ECO [{$eco->eco_number}] must be approved prior to implementation.");
            }

            // Process payload modifications if BOM or Routing changes are present
            $payload = $eco->change_payload ?? [];
            if (! empty($payload['bom_id']) && ! empty($payload['new_revision'])) {
                $this->reviseBom(
                    bomId: (string) $payload['bom_id'],
                    newRevision: (string) $payload['new_revision'],
                    reason: "Implemented via ECO {$eco->eco_number}"
                );
            }

            $eco->update([
                'status' => 'implemented',
                'implemented_at' => now(),
            ]);

            $this->auditLedger->record('mfg.eco.implemented', $eco, null, ['eco_number' => $eco->eco_number], '30_Manufacturing');

            return $eco;
        });
    }
}
