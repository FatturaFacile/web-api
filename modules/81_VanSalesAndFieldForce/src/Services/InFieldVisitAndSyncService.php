<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitMode;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitOutcomeStatus;
use Modules\VanSalesAndFieldForce\Events\InFieldDocumentIssuedEvent;
use Modules\VanSalesAndFieldForce\Events\OfflineVanDeltaSyncedEvent;
use Modules\VanSalesAndFieldForce\Exceptions\VanSellingException;
use Modules\VanSalesAndFieldForce\Models\FieldRoutePlan;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;

/**
 * Records in-field customer visit outcomes (Van Selling immediate stock unload or Field Pre-Sale orders),
 * enforces credit limit & overdue checks, allocates collision-free dedicated van sectional numbers,
 * queues SDI / generates Swiss QR-Bill references, builds ESC/POS & ZPL thermal print payloads,
 * and processes idempotent bidirectional offline delta sync batches.
 */
final class InFieldVisitAndSyncService
{
    public function __construct(
        private readonly DedicatedVanSectionalService $sectionals,
        private readonly CustomerFieldCreditAndReorderService $creditAndReorder,
        private readonly ThermalPrintPayloadBuilderService $thermalPrinter,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordVisitOutcome(string $tenantId, array $data): VanVisitOutcome
    {
        $idempotencyKey = isset($data['client_idempotency_key']) && is_string($data['client_idempotency_key'])
            ? trim($data['client_idempotency_key'])
            : null;

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = VanVisitOutcome::query()
                ->where('tenant_id', $tenantId)
                ->where('client_idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($tenantId, $data, $idempotencyKey): VanVisitOutcome {
            /** @var FieldRoutePlan $routePlan */
            $routePlan = FieldRoutePlan::query()->where('tenant_id', $tenantId)->findOrFail($data['field_route_plan_id']);
            /** @var VanWarehouse $van */
            $van = VanWarehouse::query()->where('tenant_id', $tenantId)->findOrFail($routePlan->van_warehouse_id);
            /** @var Contact $contact */
            $contact = Contact::query()->where('tenant_id', $tenantId)->findOrFail($data['contact_id']);

            $visitMode = VisitMode::from((string) ($data['visit_mode'] ?? VisitMode::VanSelling->value));
            $outcomeStatus = VisitOutcomeStatus::from((string) ($data['outcome_status'] ?? VisitOutcomeStatus::CompletedSale->value));

            $saleLines = $this->normalizeSaleLines($tenantId, (array) ($data['sale_lines'] ?? []));
            $returnLines = (array) ($data['return_lines'] ?? []);
            $emptiesMovements = (array) ($data['empties_movements'] ?? []);
            $collections = (array) ($data['collections'] ?? []);

            [$totalNet, $totalTax, $totalGross] = $this->computeTotals($saleLines);
            $returnsDeduction = $this->sumReturns($returnLines);
            $emptiesNetDeposit = $this->sumEmptiesNet($emptiesMovements);

            $creditEval = $this->creditAndReorder->evaluateCredit($tenantId, $contact, $totalGross);
            $bypassCredit = (bool) ($data['credit_override'] ?? false);

            if ($outcomeStatus === VisitOutcomeStatus::CompletedSale && $visitMode === VisitMode::VanSelling && $creditEval['is_blocked'] && ! $bypassCredit) {
                return VanVisitOutcome::create([
                    'tenant_id' => $tenantId,
                    'field_route_plan_id' => $routePlan->id,
                    'van_warehouse_id' => $van->id,
                    'van_load_manifest_id' => $data['van_load_manifest_id'] ?? $routePlan->van_load_manifest_id,
                    'contact_id' => $contact->id,
                    'stop_sequence' => (int) ($data['stop_sequence'] ?? 1),
                    'visit_mode' => $visitMode,
                    'outcome_status' => VisitOutcomeStatus::CreditBlocked,
                    'credit_check_passed' => false,
                    'credit_limit_amount' => $creditEval['credit_limit'],
                    'exposure_before_visit' => $creditEval['open_exposure'],
                    'overdue_amount_before_visit' => $creditEval['overdue_amount'],
                    'credit_blocked_initially' => true,
                    'sale_lines' => $saleLines,
                    'return_lines' => $returnLines,
                    'empties_movements' => $emptiesMovements,
                    'total_net' => $totalNet,
                    'total_tax' => $totalTax,
                    'total_gross' => $totalGross,
                    'currency' => (string) ($data['currency'] ?? ($contact->country === 'CH' ? 'CHF' : 'EUR')),
                    'printer_format' => $van->printer_profile,
                    'client_idempotency_key' => $idempotencyKey,
                    'offline_created_at' => $data['offline_created_at'] ?? null,
                    'synced_at' => now(),
                    'sync_version' => (int) $van->sync_version + 1,
                    'visited_at' => $data['visited_at'] ?? now(),
                    'notes' => $data['notes'] ?? 'Blocked by customer credit limit / overdue invoices',
                ]);
            }

            $docType = isset($data['document_type']) && is_string($data['document_type'])
                ? VanFiscalDocumentType::from($data['document_type'])
                : ($visitMode === VisitMode::FieldPresale ? VanFiscalDocumentType::SalesOrder : VanFiscalDocumentType::ImmediateInvoice);

            $sectionalCode = null;
            $definitiveNumber = null;
            $invoiceId = null;
            $deliveryNoteId = null;
            $salesOrderId = null;
            $sdiStatus = 'not_applicable';
            $qrBillGenerated = false;
            $qrBillRef = null;
            $currency = (string) ($data['currency'] ?? ($contact->country === 'CH' ? 'CHF' : 'EUR'));
            $collectedTotal = $this->sumCollections($collections, (float) ($data['collected_amount'] ?? 0));

            if (in_array($outcomeStatus, [VisitOutcomeStatus::CompletedSale, VisitOutcomeStatus::PresaleOrder], true) && $saleLines !== []) {
                $alloc = $this->sectionals->allocateNext(
                    $van,
                    $docType,
                    isset($data['offline_document_number']) && is_string($data['offline_document_number']) ? $data['offline_document_number'] : null,
                );
                $sectionalCode = $alloc['sectional_code'];
                $definitiveNumber = $alloc['document_number'];

                if ($docType === VanFiscalDocumentType::ImmediateInvoice || $docType === VanFiscalDocumentType::CommercialReceiptRt) {
                    $invoice = $this->createInvoiceFromVisit($tenantId, $contact, $van, $definitiveNumber, $alloc['sequential_number'], $saleLines, $totalNet, $totalTax, $totalGross, $currency, $collectedTotal, $data['payment_method'] ?? null);
                    $invoiceId = (string) $invoice->id;
                    if ($contact->country === 'CH') {
                        $qrBillGenerated = true;
                        $qrBillRef = 'CH-QR-'.strtoupper(substr(str_replace('-', '', $invoiceId), 0, 12));
                        $invoice->qr_reference = $qrBillRef;
                        $invoice->save();
                    } else {
                        $sdiStatus = 'queued_for_sdi';
                    }
                } elseif ($docType === VanFiscalDocumentType::DeliveryNoteDdt) {
                    $ddt = $this->createDeliveryNoteFromVisit($tenantId, $contact, $van, $definitiveNumber, $alloc['sequential_number'], $saleLines);
                    $deliveryNoteId = (string) $ddt->id;
                } elseif ($docType === VanFiscalDocumentType::SalesOrder) {
                    $order = $this->createSalesOrderFromVisit($tenantId, $contact, $definitiveNumber, $saleLines, $totalNet, $totalTax, $totalGross, $currency);
                    $salesOrderId = (string) $order->id;
                }

                // Unload sold stock & record empties on the van warehouse for physical van selling
                if ($visitMode === VisitMode::VanSelling) {
                    $this->applyVanStockUnload($van, $saleLines, $returnLines, $emptiesMovements);
                }
            }

            $printerProfile = isset($data['printer_format'])
                ? ThermalPrinterProfile::from((string) $data['printer_format'])
                : $van->printer_profile;

            $visit = VanVisitOutcome::create([
                'tenant_id' => $tenantId,
                'field_route_plan_id' => $routePlan->id,
                'van_warehouse_id' => $van->id,
                'van_load_manifest_id' => $data['van_load_manifest_id'] ?? $routePlan->van_load_manifest_id,
                'contact_id' => $contact->id,
                'stop_sequence' => (int) ($data['stop_sequence'] ?? 1),
                'visit_mode' => $visitMode,
                'outcome_status' => $outcomeStatus,
                'no_sale_reason_code' => $data['no_sale_reason_code'] ?? null,
                'rescheduled_to' => $data['rescheduled_to'] ?? null,
                'document_type' => $definitiveNumber !== null ? $docType : null,
                'fiscal_sectional_code' => $sectionalCode,
                'definitive_document_number' => $definitiveNumber,
                'invoice_id' => $invoiceId,
                'delivery_note_id' => $deliveryNoteId,
                'sales_order_id' => $salesOrderId,
                'sdi_transmission_status' => $sdiStatus,
                'qr_bill_generated' => $qrBillGenerated,
                'qr_bill_reference' => $qrBillRef,
                'credit_check_passed' => ! $creditEval['is_blocked'] || $bypassCredit,
                'credit_limit_amount' => $creditEval['credit_limit'],
                'exposure_before_visit' => $creditEval['open_exposure'],
                'overdue_amount_before_visit' => $creditEval['overdue_amount'],
                'credit_blocked_initially' => (bool) ($data['credit_blocked_initially'] ?? false),
                'unblocked_by_collection' => (bool) ($data['unblocked_by_collection'] ?? false),
                'unblocking_payment_id' => $data['unblocking_payment_id'] ?? null,
                'collected_overdue_amount' => (float) ($data['collected_overdue_amount'] ?? 0),
                'sale_lines' => $saleLines,
                'return_lines' => $returnLines,
                'empties_movements' => $emptiesMovements,
                'collections' => $collections,
                'total_net' => $totalNet,
                'total_tax' => $totalTax,
                'total_gross' => $totalGross,
                'empties_net_deposit_amount' => $emptiesNetDeposit,
                'returns_deduction_amount' => $returnsDeduction,
                'total_collected_amount' => $collectedTotal,
                'payment_method' => $data['payment_method'] ?? ($collections[0]['method'] ?? null),
                'currency' => $currency,
                'printer_format' => $printerProfile,
                'client_idempotency_key' => $idempotencyKey,
                'offline_created_at' => $data['offline_created_at'] ?? null,
                'synced_at' => now(),
                'sync_version' => (int) $van->sync_version + 1,
                'gps_latitude' => $data['gps_latitude'] ?? null,
                'gps_longitude' => $data['gps_longitude'] ?? null,
                'visited_at' => $data['visited_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            if ($definitiveNumber !== null) {
                $tenantName = (string) (Tenant::query()->whereKey($tenantId)->value('legal_name') ?? 'FatturaFacile');
                $printPayload = $this->thermalPrinter->buildForVisit($visit, $van, [
                    'customer_name' => $contact->company_name ?: $contact->first_name.' '.$contact->last_name,
                    'company_name' => $tenantName,
                ]);
                $visit->thermal_print_payload = $printerProfile === ThermalPrinterProfile::Zpl104mm
                    ? $printPayload->zplCommands
                    : $printPayload->escposCommands;
                $visit->save();

                event(new InFieldDocumentIssuedEvent(
                    visitOutcomeId: (string) $visit->id,
                    tenantId: $tenantId,
                    vanWarehouseId: (string) $van->id,
                    contactId: (string) $contact->id,
                    documentType: $docType->value,
                    definitiveDocumentNumber: $definitiveNumber,
                    totalGross: $totalGross,
                ));
            }

            $routePlan->completed_stops = $routePlan->visitOutcomes()->count();
            $routePlan->sync_version = (int) $routePlan->sync_version + 1;
            $routePlan->save();

            return $visit->fresh(['contact', 'vanWarehouse', 'routePlan']) ?? $visit;
        });
    }

    /**
     * Bidirectional delta sync endpoint: ingests offline-queued visits & temperature logs
     * with idempotency and conflict metadata, and returns updated server state for the van.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function syncOfflineBatch(string $tenantId, array $payload): array
    {
        /** @var VanWarehouse $van */
        $van = VanWarehouse::query()->where('tenant_id', $tenantId)->findOrFail($payload['van_warehouse_id']);
        $clientSyncVersion = (int) ($payload['client_sync_version'] ?? 0);
        $hasVersionDrift = $clientSyncVersion < (int) $van->sync_version;

        $processed = [];
        $conflicts = [];

        foreach ((array) ($payload['visits'] ?? []) as $visitData) {
            try {
                $visit = $this->recordVisitOutcome($tenantId, $visitData);
                $processed[] = [
                    'visit_id' => (string) $visit->id,
                    'client_idempotency_key' => $visit->client_idempotency_key,
                    'outcome_status' => $visit->outcome_status->value,
                    'definitive_document_number' => $visit->definitive_document_number,
                    'sdi_transmission_status' => $visit->sdi_transmission_status,
                    'qr_bill_reference' => $visit->qr_bill_reference,
                ];
            } catch (VanSellingException $e) {
                $conflicts[] = [
                    'client_idempotency_key' => $visitData['client_idempotency_key'] ?? null,
                    'error_key' => $e->errorKey,
                    'message' => $e->getMessage(),
                ];
            }
        }

        foreach ((array) ($payload['temperature_logs'] ?? []) as $tempEntry) {
            $temp = (float) ($tempEntry['temp_celsius'] ?? 0);
            $log = (array) ($van->temperature_log ?? []);
            $log[] = [
                'recorded_at' => $tempEntry['recorded_at'] ?? now()->toIso8601String(),
                'temp_celsius' => $temp,
                'source' => 'offline_sync',
            ];
            $van->temperature_log = array_slice($log, -100);
            $van->current_temp_celsius = (string) $temp;
            $van->last_temp_recorded_at = now();
            if ($van->has_cold_chain) {
                $min = (float) ($van->min_temp_celsius ?? -30);
                $max = (float) ($van->max_temp_celsius ?? 10);
                $van->temp_alert_active = $temp < $min || $temp > $max;
            }
        }

        $van->sync_version = (int) $van->sync_version + 1;
        $van->last_synced_at = now();
        $van->save();

        event(new OfflineVanDeltaSyncedEvent(
            vanWarehouseId: (string) $van->id,
            tenantId: $tenantId,
            processedCount: count($processed),
            conflictCount: count($conflicts),
            syncVersion: (int) $van->sync_version,
        ));

        return [
            'van_warehouse_id' => (string) $van->id,
            'server_sync_version' => (int) $van->sync_version,
            'version_drift_detected' => $hasVersionDrift,
            'encryption_algorithm' => 'AES-256-GCM',
            'synced_at' => now()->toIso8601String(),
            'processed_count' => count($processed),
            'conflict_count' => count($conflicts),
            'processed_visits' => $processed,
            'conflicts' => $conflicts,
            'server_delta' => [
                'current_stock_snapshot' => $van->current_stock_snapshot ?? [],
                'empties_onboard_snapshot' => $van->empties_onboard_snapshot ?? [],
                'next_sectionals' => [
                    'invoice' => $van->invoice_sectional_code.'/'.$van->fiscal_year.'/'.str_pad((string) $van->invoice_next_number, 5, '0', STR_PAD_LEFT),
                    'ddt' => $van->ddt_sectional_code.'/'.$van->fiscal_year.'/'.str_pad((string) $van->ddt_next_number, 5, '0', STR_PAD_LEFT),
                    'receipt' => $van->receipt_sectional_code.'/'.$van->fiscal_year.'/'.str_pad((string) $van->receipt_next_number, 5, '0', STR_PAD_LEFT),
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return list<array<string, mixed>>
     */
    private function normalizeSaleLines(string $tenantId, array $rawLines): array
    {
        $productIds = array_values(array_filter(array_map(static fn (array $l): string => (string) ($l['product_id'] ?? ''), $rawLines)));
        $products = Product::query()->where('tenant_id', $tenantId)->whereIn('id', $productIds)->get()->keyBy('id');

        $lines = [];
        foreach ($rawLines as $line) {
            $pid = (string) ($line['product_id'] ?? '');
            /** @var Product|null $product */
            $product = $products->get($pid);
            $qty = round((float) ($line['quantity'] ?? 0), 4);
            if ($product === null || $qty <= 0) {
                continue;
            }
            $isFreebie = (bool) ($line['is_freebie'] ?? false);
            $unitPrice = $isFreebie ? 0.0 : (isset($line['unit_price']) ? (float) $line['unit_price'] : (float) $product->sale_price);
            $taxRate = isset($line['tax_rate']) ? (float) $line['tax_rate'] : (float) ($product->tax_rate ?? 22.0);
            $lineNet = round($unitPrice * $qty, 2);
            $lineTax = round($lineNet * ($taxRate / 100), 2);

            $lines[] = [
                'product_id' => $pid,
                'sku' => $product->sku,
                'description' => (string) ($line['description'] ?? $product->name),
                'unit_measure' => (string) ($line['unit_measure'] ?? $product->unit_measure ?? 'pz'),
                'quantity' => $qty,
                'lot_number' => $line['lot_number'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'gs1_barcode' => $line['gs1_barcode'] ?? null,
                'is_variable_weight' => (bool) ($line['is_variable_weight'] ?? false),
                'is_freebie' => $isFreebie,
                'unit_price' => round($unitPrice, 4),
                'tax_rate' => round($taxRate, 2),
                'line_total' => $lineNet,
                'line_tax' => $lineTax,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: float, 1: float, 2: float}
     */
    private function computeTotals(array $lines): array
    {
        $net = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $net += (float) $line['line_total'];
            $tax += (float) $line['line_tax'];
        }
        $net = round($net, 2);
        $tax = round($tax, 2);

        return [$net, $tax, round($net + $tax, 2)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $returnLines
     */
    private function sumReturns(array $returnLines): float
    {
        $sum = 0.0;
        foreach ($returnLines as $line) {
            $sum += (float) ($line['credit_amount'] ?? ((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0)));
        }

        return round($sum, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $empties
     */
    private function sumEmptiesNet(array $empties): float
    {
        $sum = 0.0;
        foreach ($empties as $row) {
            $delivered = (int) ($row['delivered_qty'] ?? 0);
            $returned = (int) ($row['returned_qty'] ?? 0);
            $deposit = (float) ($row['unit_deposit'] ?? 0);
            $sum += ($delivered - $returned) * $deposit;
        }

        return round($sum, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $collections
     */
    private function sumCollections(array $collections, float $fallback): float
    {
        if ($collections === []) {
            return round($fallback, 2);
        }
        $sum = 0.0;
        foreach ($collections as $c) {
            $sum += (float) ($c['amount'] ?? 0);
        }

        return round($sum, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $saleLines
     */
    private function createInvoiceFromVisit(
        string $tenantId,
        Contact $contact,
        VanWarehouse $van,
        string $docNumber,
        int $seqNumber,
        array $saleLines,
        float $net,
        float $tax,
        float $gross,
        string $currency,
        float $collected,
        ?string $paymentMethod,
    ): Invoice {
        $isPaid = $collected >= ($gross - 0.01) && $gross > 0;
        $invoice = Invoice::create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'invoice_type' => 'standard',
            'document_type' => 'TD01',
            'invoice_number' => $docNumber,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays((int) ($contact->payment_terms_days ?? 30))->toDateString(),
            'currency' => $currency,
            'jurisdiction' => $contact->country === 'CH' ? 'CH' : 'IT',
            'total_net' => $net,
            'total_tax' => $tax,
            'total_gross' => $gross,
            'total_paid' => min($gross, $collected),
            'payment_status' => $isPaid ? 'paid' : ($collected > 0 ? 'partial' : 'unpaid'),
            'payment_method' => $paymentMethod ?? 'cash',
            'fiscal_year' => $van->fiscal_year,
            'sequential_number' => $seqNumber,
            'notes' => 'Van Selling (Mod 233) - '.$van->license_plate,
        ]);

        foreach ($saleLines as $line) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $line['product_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'line_total' => $line['line_total'],
            ]);
        }

        if ($collected > 0) {
            Payment::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'amount' => min($gross, $collected),
                'paid_at' => now()->toDateString(),
                'payment_method' => $paymentMethod ?? 'cash',
                'reference' => 'VAN-PAY-'.strtoupper(Str::random(6)),
                'notes' => 'In-field van collection',
                'type' => 'receipt',
            ]);
        }

        return $invoice;
    }

    /**
     * @param  list<array<string, mixed>>  $saleLines
     */
    private function createDeliveryNoteFromVisit(
        string $tenantId,
        Contact $contact,
        VanWarehouse $van,
        string $docNumber,
        int $seqNumber,
        array $saleLines,
    ): DeliveryNote {
        $ddt = DeliveryNote::create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'warehouse_id' => $van->warehouse_id,
            'delivery_number' => $docNumber,
            'date' => now()->toDateString(),
            'status' => 'delivered',
            'transport_reason_code' => 'vendita',
            'reason' => 'Vendita in Tentata Vendita - '.$van->license_plate,
            'fiscal_year' => $van->fiscal_year,
            'sequential_number' => $seqNumber,
            'issued_at' => now(),
            'delivered_at' => now(),
        ]);

        foreach ($saleLines as $line) {
            DeliveryNoteItem::create([
                'tenant_id' => $tenantId,
                'delivery_note_id' => $ddt->id,
                'product_id' => $line['product_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
            ]);
        }

        return $ddt;
    }

    /**
     * @param  list<array<string, mixed>>  $saleLines
     */
    private function createSalesOrderFromVisit(
        string $tenantId,
        Contact $contact,
        string $docNumber,
        array $saleLines,
        float $net,
        float $tax,
        float $gross,
        string $currency,
    ): SalesOrder {
        $order = SalesOrder::create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'order_number' => $docNumber,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => $currency,
            'total_net' => $net,
            'total_tax' => $tax,
            'total_gross' => $gross,
            'status' => 'confirmed',
            'notes' => 'Field Sales Pre-Sale Order (Mod 233)',
        ]);

        foreach ($saleLines as $line) {
            SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'product_id' => $line['product_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'line_total' => $line['line_total'],
            ]);
        }

        return $order;
    }

    /**
     * @param  list<array<string, mixed>>  $saleLines
     * @param  array<int, array<string, mixed>>  $returnLines
     * @param  array<int, array<string, mixed>>  $emptiesMovements
     */
    private function applyVanStockUnload(VanWarehouse $van, array $saleLines, array $returnLines, array $emptiesMovements): void
    {
        $vanWh = $van->warehouse_id ? Warehouse::query()->find($van->warehouse_id) : null;
        $snapshot = (array) ($van->current_stock_snapshot ?? []);

        foreach ($saleLines as $line) {
            $pid = (string) $line['product_id'];
            $qty = (float) $line['quantity'];
            $lot = $line['lot_number'] ?? null;

            if ($vanWh !== null) {
                $this->stock->record($pid, 'out', $qty, null, $vanWh, 0.0, 'Van sale unload '.$van->license_plate);
            }

            foreach ($snapshot as $idx => $row) {
                if ((string) ($row['product_id'] ?? '') === $pid && ($lot === null || ($row['lot_number'] ?? null) === $lot)) {
                    $snapshot[$idx]['quantity_onboard'] = max(0.0, round((float) ($row['quantity_onboard'] ?? 0) - $qty, 4));
                    break;
                }
            }
        }

        $empties = (array) ($van->empties_onboard_snapshot ?? []);
        foreach ($emptiesMovements as $mov) {
            $code = (string) ($mov['item_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $delta = (int) ($mov['returned_qty'] ?? 0) - (int) ($mov['delivered_qty'] ?? 0);
            $found = false;
            foreach ($empties as $idx => $erow) {
                if ((string) ($erow['item_code'] ?? '') === $code) {
                    $empties[$idx]['quantity_onboard'] = max(0, (int) ($erow['quantity_onboard'] ?? 0) + $delta);
                    $found = true;
                    break;
                }
            }
            if (! $found && $delta > 0) {
                $empties[] = [
                    'item_code' => $code,
                    'item_name' => $mov['item_name'] ?? $code,
                    'quantity_onboard' => $delta,
                ];
            }
        }

        $van->current_stock_snapshot = $snapshot;
        $van->empties_onboard_snapshot = $empties;
        $van->sync_version = (int) $van->sync_version + 1;
        $van->save();
    }
}
