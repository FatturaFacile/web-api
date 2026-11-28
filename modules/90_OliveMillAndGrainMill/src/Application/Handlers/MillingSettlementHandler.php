<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Handlers;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\OliveMillAndGrainMill\Application\Commands\SettleMillingCycleCommand;
use Modules\OliveMillAndGrainMill\Application\Services\OilProfile;
use Modules\OliveMillAndGrainMill\Application\Services\SianRegister;
use Modules\OliveMillAndGrainMill\Application\Services\TankLedger;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\CycleStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianCausalCode;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Domain\Services\MolendaCompensationService;
use Modules\OliveMillAndGrainMill\Domain\ValueObjects\MolendaCalculation;
use Modules\OliveMillAndGrainMill\Events\MillingSettled;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * Settlement of a milling service: cash, in kind (molenda) or hybrid. One transaction creates the active invoice of the mill for the service, the
 * purchase document of the oil the mill keeps in payment, the SIAN load of that oil into the chosen tank (C03), the SIAN return of the oil left
 * to the farmer (S02) and the settlement with the compensation between the two documents.
 */
final class MillingSettlementHandler
{
    public function __construct(
        private readonly MolendaCompensationService $calculator,
        private readonly InvoicingServiceInterface $invoicing,
        private readonly TankLedger $tanks,
        private readonly SianRegister $register,
        private readonly OilProfile $profile,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The calculation of the settlement, with nothing written.
     *
     * @return array<string, mixed>
     *
     * @throws OliveMillException
     */
    public function preview(SettleMillingCycleCommand $command): array
    {
        $cycle = MillingCycle::query()->with('delivery.farmer')->whereKey($command->cycleId)->first() ?? throw OliveMillException::because('cycle_not_found', [], 404);
        $this->assertSettleable($cycle);
        $calculation = $this->calculate($cycle, $command);

        return [
            'cycle_id' => (string) $cycle->id,
            'lot_code' => $cycle->delivery->lot_code,
            'olive_net_kg' => $cycle->input_net_kg,
            'output_kg' => $cycle->output_kg,
            'available_kg' => $cycle->molenda_kg,
            'calculation' => $calculation->toArray(),
            'documents' => [
                'service_invoice' => ['net' => $calculation->serviceNet, 'vat' => $calculation->serviceVat, 'total' => $calculation->serviceTotal],
                'purchase_document' => ['net' => $calculation->retainedValueNet, 'vat' => $calculation->purchaseVat, 'total' => $calculation->purchaseTotal],
            ],
            'requires_tank' => (float) $calculation->retainedKg > 0.0 && $cycle->delivery->commodity === Commodity::Olive,
        ];
    }

    /**
     * @throws OliveMillException
     */
    public function handle(SettleMillingCycleCommand $command): MillingSettlement
    {
        return DB::transaction(function () use ($command): MillingSettlement {
            $cycle = MillingCycle::query()->whereKey($command->cycleId)->lockForUpdate()->first() ?? throw OliveMillException::because('cycle_not_found', [], 404);
            $this->assertSettleable($cycle);

            $delivery = OliveDelivery::query()->whereKey($cycle->delivery_id)->lockForUpdate()->firstOrFail();
            $farmer = Contact::query()->find($delivery->farmer_contact_id) ?? throw OliveMillException::because('farmer_not_found', [], 404);
            $calculation = $this->calculate($cycle, $command);
            $retained = (float) $calculation->retainedKg;
            $isOil = $delivery->commodity === Commodity::Olive;

            if ((float) $calculation->serviceNet <= 0.0) {
                throw OliveMillException::because('service_amount_zero');
            }

            $leftover = round((float) $cycle->molenda_kg - $retained, 3);
            $movementId = $isOil ? $this->moveOil($cycle, $delivery, $farmer, $command, $retained, $leftover) : null;

            $invoice = $this->emitServiceInvoice($delivery, $farmer, $calculation, $command);
            $purchase = $retained > 0.0 ? $this->recordPurchaseDocument($delivery, $farmer, $calculation) : null;

            $settlement = MillingSettlement::query()->create([
                'delivery_id' => $delivery->id,
                'cycle_id' => $cycle->id,
                'tariff_mode' => $command->tariffMode,
                'tariff_value' => $command->tariffValue,
                'service_net' => $calculation->serviceNet,
                'service_vat_rate' => $calculation->serviceVatRate,
                'service_vat' => $calculation->serviceVat,
                'service_total' => $calculation->serviceTotal,
                'settlement_mode' => $command->settlementMode,
                'molenda_percent' => $calculation->retainedPercent,
                'retained_kg' => $calculation->retainedKg,
                'oil_price_per_kg' => $calculation->oilPricePerKg,
                'retained_value_net' => $calculation->retainedValueNet,
                'purchase_vat_rate' => $calculation->purchaseVatRate,
                'purchase_vat' => $calculation->purchaseVat,
                'purchase_total' => $calculation->purchaseTotal,
                'cash_balance' => $calculation->cashBalance,
                'compensation_amount' => $calculation->compensation,
                'tank_id' => $retained > 0.0 ? $command->tankId : null,
                'service_invoice_id' => $invoice->id,
                'purchase_doc_id' => $purchase?->id,
                'sian_movement_id' => $movementId,
                'status' => 'SETTLED',
                'settled_at' => now(),
                'created_by' => $command->userId,
            ]);

            $cycle->forceFill([
                'status' => CycleStatus::Settled,
                'molenda_kg' => $retained,
                'pickup_kg' => round((float) $cycle->pickup_kg + max(0.0, $leftover), 3),
            ])->save();
            $delivery->forceFill(['status' => DeliveryStatus::Settled])->save();

            event(new MillingSettled((string) $settlement->id, (string) $cycle->id, $retained, (float) $calculation->cashBalance));

            return $settlement->refresh();
        });
    }

    /**
     * @throws OliveMillException cycle_not_completed, cycle_already_settled, own_account_no_settlement
     */
    private function assertSettleable(MillingCycle $cycle): void
    {
        if ($cycle->status === CycleStatus::Settled || MillingSettlement::query()->where('cycle_id', $cycle->id)->exists()) {
            throw OliveMillException::because('cycle_already_settled');
        }

        if ($cycle->status !== CycleStatus::Completed) {
            throw OliveMillException::because('cycle_not_completed', ['status' => $cycle->status->value]);
        }

        $delivery = $cycle->relationLoaded('delivery') ? $cycle->delivery : OliveDelivery::query()->find($cycle->delivery_id);

        if ($delivery === null || $delivery->processing_mode === ProcessingMode::ContoProprio) {
            throw OliveMillException::because('own_account_no_settlement');
        }
    }

    private function calculate(MillingCycle $cycle, SettleMillingCycleCommand $command): MolendaCalculation
    {
        return $this->calculator->calculate(
            oliveNetKg: (float) $cycle->input_net_kg,
            outputKg: (float) $cycle->output_kg,
            availableKg: (float) $cycle->molenda_kg,
            tariff: $command->tariffMode,
            tariffValue: $command->tariffValue,
            serviceVatRate: $command->serviceVatRate ?? (float) config('olive_mill.vat.milling_service', 22.0),
            mode: $command->settlementMode,
            molendaPercent: $command->molendaPercent,
            fixedRetainedKg: $command->fixedRetainedKg,
            oilPricePerKg: $command->oilPricePerKg ?? 0.0,
            purchaseVatRate: $command->purchaseVatRate ?? (float) config('olive_mill.vat.oil_purchase', 4.0),
        );
    }

    /**
     * Loads the retained oil into the tank (C03) and returns the leftover to the farmer (S02). Returns the id of the C03 operation.
     */
    private function moveOil(MillingCycle $cycle, OliveDelivery $delivery, Contact $farmer, SettleMillingCycleCommand $command, float $retained, float $leftover): ?string
    {
        $category = $this->profile->category($delivery, $cycle);
        $origin = $this->profile->origin($delivery, null);
        $common = ['category' => $category, 'origin' => $origin, 'lot_code' => $delivery->lot_code, 'source_type' => 'milling_settlement', 'source_id' => (string) $cycle->id, 'counterparty' => $farmer, 'user_id' => $command->userId];
        $movementId = null;

        if ($retained > 0.0) {
            if ($command->tankId === null) {
                throw OliveMillException::because('target_tank_required');
            }

            $tank = $this->tanks->lock($command->tankId);
            $this->tanks->load($tank, $retained, $category, $origin, $delivery->lot_code);
            $movementId = (string) $this->register->record(SianCausalCode::C03, now(), $common + ['to_tank_id' => (string) $tank->id, 'quantity_kg' => $retained])->id;
        }

        if ($leftover > 0.0005) {
            $this->register->record(SianCausalCode::S02, now(), $common + ['quantity_kg' => $leftover]);
        }

        return $movementId;
    }

    private function emitServiceInvoice(OliveDelivery $delivery, Contact $farmer, MolendaCalculation $calculation, SettleMillingCycleCommand $command): SalesInvoice
    {
        $items = [[
            'description' => __('olive_mill.invoice.service_line', ['lot' => $delivery->lot_code, 'kg' => $delivery->net_weight_kg]),
            'quantity' => 1,
            'unit_price' => $calculation->serviceNet,
            'tax_rate' => (float) $calculation->serviceVatRate,
        ]];

        $note = (float) $calculation->retainedKg > 0.0
            ? __('olive_mill.invoice.compensation_note', ['kg' => $calculation->retainedKg, 'amount' => $calculation->compensation, 'balance' => $calculation->cashBalance])
            : null;

        return $this->invoicing->createInvoice([
            'tenant_id' => (string) $delivery->tenant_id,
            'contact_id' => (string) $farmer->id,
            'invoice_type' => 'standard',
            'document_type' => 'TD01',
            'date' => now()->toDateString(),
            'currency' => 'EUR',
            'jurisdiction' => 'IT',
            'notes' => trim(($note ?? '').' '.($command->notes ?? '')) ?: null,
            'items' => $items,
            'created_by' => $command->userId,
        ], $items);
    }

    /**
     * The document that records the purchase of the oil kept by the mill: it is the other side of the compensation with the service invoice.
     */
    private function recordPurchaseDocument(OliveDelivery $delivery, Contact $farmer, MolendaCalculation $calculation): PassiveInvoice
    {
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $delivery->tenant_id);

        $document = PassiveInvoice::create([
            'tenant_id' => $tenantId,
            'supplier_id' => (string) $farmer->id,
            'invoice_number' => 'MOL-'.$delivery->lot_code,
            'date' => now()->toDateString(),
            'total_amount' => $calculation->purchaseTotal,
            'total_net' => $calculation->retainedValueNet,
            'total_tax' => $calculation->purchaseVat,
            'currency' => 'EUR',
            'document_type' => 'TD01',
            'payment_status' => 'unpaid',
            'total_paid' => 0,
            'document_hash' => 'olive-mill:'.$delivery->id,
        ]);

        PassiveInvoiceLine::create([
            'tenant_id' => $tenantId,
            'passive_invoice_id' => $document->id,
            'line_number' => 1,
            'description' => __('olive_mill.invoice.purchase_line', ['lot' => $delivery->lot_code]),
            'quantity' => $calculation->retainedKg,
            'unit_of_measure' => 'KG',
            'unit_price' => $calculation->oilPricePerKg,
            'line_total' => $calculation->retainedValueNet,
            'tax_rate' => $calculation->purchaseVatRate,
        ]);

        return $document;
    }
}
