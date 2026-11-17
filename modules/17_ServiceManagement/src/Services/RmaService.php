<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Contracts\EquipmentPassportServiceInterface;
use Modules\ServiceManagement\Contracts\RmaServiceInterface;
use Modules\ServiceManagement\DTOs\BenchDiagnosisDTO;
use Modules\ServiceManagement\DTOs\CompleteRmaRepairDTO;
use Modules\ServiceManagement\DTOs\IssueRmaDTO;
use Modules\ServiceManagement\DTOs\ReceiveRmaDTO;
use Modules\ServiceManagement\DTOs\ReturnRmaDTO;
use Modules\ServiceManagement\Enums\DdtCausale;
use Modules\ServiceManagement\Enums\EstimateApprovalStatus;
use Modules\ServiceManagement\Enums\LoanerRegisterType;
use Modules\ServiceManagement\Enums\LoanerStatus;
use Modules\ServiceManagement\Enums\RepairOutcome;
use Modules\ServiceManagement\Enums\RmaStatus;
use Modules\ServiceManagement\Enums\VendorClaimStatus;
use Modules\ServiceManagement\Enums\WarrantyStatus;
use Modules\ServiceManagement\Events\LoanerIssuedEvent;
use Modules\ServiceManagement\Events\RmaCompletedEvent;
use Modules\ServiceManagement\Events\RmaEstimateDecidedEvent;
use Modules\ServiceManagement\Events\RmaIssuedEvent;
use Modules\ServiceManagement\Events\RmaStatusChangedEvent;
use Modules\ServiceManagement\Events\RmaWarrantyCostChargedEvent;
use Modules\ServiceManagement\Events\VendorClaimOpenedEvent;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;
use Modules\ServiceManagement\Models\EquipmentComponent;
use Modules\ServiceManagement\Models\Loaner;
use Modules\ServiceManagement\Models\RmaLine;
use Modules\ServiceManagement\Models\RmaStatusHistory;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\VendorWarrantyClaim;

final readonly class RmaService implements RmaServiceInterface
{
    public const DECIDED_VIA_PUBLIC_LINK = 'public_link';

    public const DECIDED_VIA_STAFF = 'staff';

    public const DECIDED_VIA_AUTO = 'auto';

    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private EquipmentPassportServiceInterface $passportService,
        private RmaNumberGenerator $numberGenerator,
        private TenantContext $tenantContext,
    ) {}

    public function issue(SerializedEquipmentPassport $passport, IssueRmaDTO $dto, ?string $actorUserId = null): array
    {
        $evaluation = $this->passportService->evaluateWarranty($passport);
        $evaluatedAt = now();
        $inWarranty = $evaluation->isInWarrantyAt($evaluatedAt);

        $warrantyStatus = match (true) {
            $inWarranty => WarrantyStatus::CoveredInWarranty,
            $dto->goodwill => WarrantyStatus::Goodwill,
            default => WarrantyStatus::OutOfWarrantyBillable,
        };

        $rawTrackingToken = $this->newRawToken();
        $tenant = Tenant::query()->find($dto->tenantId);

        $rma = DB::transaction(function () use ($passport, $dto, $actorUserId, $evaluation, $evaluatedAt, $warrantyStatus, $rawTrackingToken, $tenant): DepotRepairRmaOrder {
            $rma = DepotRepairRmaOrder::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'rma_number' => $this->numberGenerator->next($dto->tenantId),
                'equipment_passport_id' => $passport->id,
                'customer_id' => $passport->current_owner_customer_id,
                'status' => RmaStatus::AwaitingArrival->value,
                'warranty_status' => $warrantyStatus->value,
                'warranty_evaluated_at' => $evaluatedAt,
                'warranty_basis' => $evaluation->evaluationAt($evaluatedAt),
                'reported_symptom' => $dto->reportedSymptom,
                'currency' => (string) ($tenant?->default_currency ?? 'EUR'),
                'preauthorized_threshold' => $dto->preauthorizedThreshold ?? (string) config('service-management.rma.default_threshold', '0.00'),
                'estimate_approval_status' => EstimateApprovalStatus::NotRequired->value,
                'tracking_token_hash' => $this->hashToken($rawTrackingToken),
                'ddt_causale_outbound' => $dto->ddtCausaleOutbound ?? DdtCausale::ContoRiparazione->value,
                'is_non_ownership_transfer' => true,
                'vat_exempt_art15_dpr633' => false,
            ]);

            $this->writeHistory($rma, null, RmaStatus::AwaitingArrival, RmaStatusHistory::ACTOR_USER, $actorUserId, 'RMA issued');

            $this->auditLedger->record(
                'RMA_ISSUED',
                $rma,
                null,
                ['rma_number' => $rma->rma_number, 'warranty_status' => $rma->warranty_status],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new RmaIssuedEvent(
                rmaOrderId: $rma->id,
                rmaNumber: $rma->rma_number,
                equipmentPassportId: $passport->id,
                serialNumber: $passport->serial_number,
                customerId: $rma->customer_id,
                warrantyStatus: $rma->warranty_status,
                tenantId: $rma->tenant_id,
                companyId: $this->companyIdOf($rma),
            ));

            return $rma;
        });

        return ['rma' => $this->reload($rma), 'tracking_token' => $rawTrackingToken];
    }

    public function receive(DepotRepairRmaOrder $rma, ReceiveRmaDTO $dto, ?string $actorUserId = null): DepotRepairRmaOrder
    {
        return DB::transaction(function () use ($rma, $dto, $actorUserId): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $receivedAt = $dto->receivedAt !== null ? CarbonImmutable::parse($dto->receivedAt) : CarbonImmutable::now();

            $rma->fill([
                'received_at' => $receivedAt,
                'receipt_condition_notes' => $dto->receiptConditionNotes,
                'receipt_photos' => $dto->receiptPhotos,
            ]);
            $this->transition($rma, RmaStatus::InDiagnosis, RmaStatusHistory::ACTOR_USER, $actorUserId, 'Unit received at depot');

            $passport = $rma->passport;
            $repairAccount = Loaner::create([
                'tenant_id' => $rma->tenant_id,
                'company_id' => $rma->company_id,
                'rma_order_id' => $rma->id,
                'loaner_stock_serial_number_id' => $passport?->stock_serial_number_id,
                'loaner_serial_number' => (string) ($passport?->serial_number ?? $rma->rma_number),
                'loaner_product_id' => $passport?->product_id,
                'customer_id' => $rma->customer_id,
                'register_type' => LoanerRegisterType::ContoRiparazione->value,
                'issued_at' => $receivedAt,
                'status' => LoanerStatus::Issued->value,
                'ddt_causale' => $rma->ddt_causale_outbound ?? DdtCausale::ContoRiparazione->value,
                'notes' => 'Customer unit held in depot (conto riparazione)',
            ]);

            $this->dispatcher->dispatch(new LoanerIssuedEvent(
                loanerId: $repairAccount->id,
                rmaOrderId: $rma->id,
                loanerSerialNumber: $repairAccount->loaner_serial_number,
                registerType: $repairAccount->register_type,
                customerId: $repairAccount->customer_id,
                tenantId: $rma->tenant_id,
                companyId: $this->companyIdOf($rma),
            ));

            return $this->reload($rma);
        });
    }

    public function diagnose(DepotRepairRmaOrder $rma, BenchDiagnosisDTO $dto, ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($rma, $dto, $actorUserId): array {
            $rma = $this->lockFresh($rma);
            $this->assertStatus($rma, RmaStatus::InDiagnosis, 'diagnosis');

            $warrantyStatus = $rma->warrantyStatusEnum();
            if ($dto->goodwill && $warrantyStatus === WarrantyStatus::OutOfWarrantyBillable) {
                $warrantyStatus = WarrantyStatus::Goodwill;
            }
            $chargedToProvision = $warrantyStatus->isChargedToWarrantyProvision();

            $rma->lines()->delete();
            $lines = [];
            foreach ($dto->lines as $line) {
                $lines[] = RmaLine::create([
                    'tenant_id' => $rma->tenant_id,
                    'company_id' => $rma->company_id,
                    'rma_order_id' => $rma->id,
                    'line_type' => $line['line_type'],
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'],
                    'qty' => $this->decimal($line['qty'], 4),
                    'unit_cost' => $this->decimal($line['unit_cost']),
                    'unit_price' => $chargedToProvision ? '0.00' : $this->decimal($line['unit_price']),
                    'charged_to' => $chargedToProvision ? RmaLine::CHARGED_TO_WARRANTY_PROVISION : RmaLine::CHARGED_TO_CUSTOMER,
                    'defective_part_serial' => $line['defective_part_serial'] ?? null,
                    'supplier_id' => $line['supplier_id'] ?? null,
                    'replacement_part_serial' => $line['replacement_part_serial'] ?? null,
                ]);
            }

            $hasPartLines = collect($lines)->contains(static fn (RmaLine $line): bool => $line->line_type === RmaLine::TYPE_PART);
            $partsRequired = $hasPartLines && ! $dto->partsAvailable;

            [$labourTotal, $partsTotal] = $this->priceTotals($lines);
            $total = round($labourTotal + $partsTotal, 2);

            $rma->fill([
                'warranty_status' => $warrantyStatus->value,
                'lab_bench_diagnostic_notes' => $dto->labBenchDiagnosticNotes,
                'diagnosed_at' => now(),
                'diagnosed_by_user_id' => $actorUserId,
                'parts_required' => $partsRequired,
                'estimate_labour_total' => $this->decimal($labourTotal),
                'estimate_parts_total' => $this->decimal($partsTotal),
                'estimate_total' => $this->decimal($total),
            ]);

            $rawEstimateToken = null;

            if (! $dto->repairFeasible) {
                $rma->repair_outcome = RepairOutcome::ReturnedUnrepaired->value;
                $rma->estimate_approval_status = EstimateApprovalStatus::NotRequired->value;
                $this->transition($rma, RmaStatus::ReadyForReturn, RmaStatusHistory::ACTOR_USER, $actorUserId, 'Not repairable: return unrepaired');
            } elseif ($chargedToProvision) {
                $rma->estimate_approval_status = EstimateApprovalStatus::NotRequired->value;
                $this->transition($rma, $this->repairStageFor($partsRequired), RmaStatusHistory::ACTOR_USER, $actorUserId, 'Diagnosis completed: covered by warranty provision');
                $this->chargeWarrantyProvision($rma, $lines);
                $this->openVendorClaims($rma, $lines);
            } elseif ($total <= (float) $rma->preauthorized_threshold) {
                $rma->fill([
                    'estimate_approval_status' => EstimateApprovalStatus::AutoApprovedUnderThreshold->value,
                    'estimate_decided_at' => now(),
                    'estimate_decided_via' => self::DECIDED_VIA_AUTO,
                ]);
                $this->transition($rma, $this->repairStageFor($partsRequired), RmaStatusHistory::ACTOR_SYSTEM, $actorUserId, 'Estimate auto-approved under customer pre-authorized threshold');
                $this->emitEstimateDecided($rma);
            } else {
                $rawEstimateToken = $this->newRawToken();
                $rma->fill([
                    'estimate_approval_status' => EstimateApprovalStatus::PendingCustomerApproval->value,
                    'estimate_token_hash' => $this->hashToken($rawEstimateToken),
                    'estimate_token_expires_at' => now()->addDays($this->estimateTtlDays()),
                    'estimate_decided_at' => null,
                    'estimate_decided_via' => null,
                ]);
                $this->transition($rma, RmaStatus::AwaitingEstimateApproval, RmaStatusHistory::ACTOR_USER, $actorUserId, 'Estimate sent to customer');
            }

            $this->auditLedger->record(
                'RMA_DIAGNOSED',
                $rma,
                null,
                [
                    'warranty_status' => $rma->warranty_status,
                    'estimate_total' => $rma->estimate_total,
                    'estimate_approval_status' => $rma->estimate_approval_status,
                ],
                'MODULE_17_ESM'
            );

            return ['rma' => $this->reload($rma), 'estimate_token' => $rawEstimateToken];
        });
    }

    public function approveEstimate(
        DepotRepairRmaOrder $rma,
        string $decidedVia,
        ?string $actorUserId = null,
        ?string $decidedByName = null,
        ?string $ip = null,
        ?string $note = null,
    ): DepotRepairRmaOrder {
        return DB::transaction(function () use ($rma, $decidedVia, $actorUserId, $decidedByName, $ip, $note): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $this->assertEstimatePending($rma);

            $rma->fill([
                'estimate_approval_status' => EstimateApprovalStatus::Approved->value,
                'estimate_decided_at' => now(),
                'estimate_decided_via' => $decidedVia,
                'estimate_decided_by_name' => $decidedByName,
                'estimate_decision_ip' => $ip,
                'estimate_decision_note' => $note,
            ]);

            $this->transition(
                $rma,
                $this->repairStageFor((bool) $rma->parts_required),
                $this->actorTypeFor($decidedVia),
                $actorUserId,
                $note ?? 'Estimate approved',
            );
            $this->emitEstimateDecided($rma);

            return $this->reload($rma);
        });
    }

    public function declineEstimate(
        DepotRepairRmaOrder $rma,
        string $decidedVia,
        ?string $actorUserId = null,
        ?string $decidedByName = null,
        ?string $ip = null,
        ?string $note = null,
    ): DepotRepairRmaOrder {
        return DB::transaction(function () use ($rma, $decidedVia, $actorUserId, $decidedByName, $ip, $note): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $this->assertEstimatePending($rma);

            $rma->fill([
                'estimate_approval_status' => EstimateApprovalStatus::DeclinedReturnUnrepaired->value,
                'estimate_decided_at' => now(),
                'estimate_decided_via' => $decidedVia,
                'estimate_decided_by_name' => $decidedByName,
                'estimate_decision_ip' => $ip,
                'estimate_decision_note' => $note,
                'repair_outcome' => RepairOutcome::ReturnedUnrepaired->value,
            ]);

            $this->transition($rma, RmaStatus::ReadyForReturn, $this->actorTypeFor($decidedVia), $actorUserId, $note ?? 'Estimate declined: return unrepaired');
            $this->emitEstimateDecided($rma);

            return $this->reload($rma);
        });
    }

    public function resendEstimate(DepotRepairRmaOrder $rma, ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($rma): array {
            $rma = $this->lockFresh($rma);

            if ($rma->estimate_approval_status !== EstimateApprovalStatus::PendingCustomerApproval->value) {
                throw new WarrantyRmaException('Only a pending estimate can be re-sent.', 'estimate_not_pending', 409);
            }

            $raw = $this->newRawToken();
            $rma->estimate_token_hash = $this->hashToken($raw);
            $rma->estimate_token_expires_at = now()->addDays($this->estimateTtlDays());
            $rma->save();

            $this->auditLedger->record('RMA_ESTIMATE_RESENT', $rma, null, ['rma_number' => $rma->rma_number], 'MODULE_17_ESM');

            return ['rma' => $this->reload($rma), 'estimate_token' => $raw];
        });
    }

    public function markPartsReceived(DepotRepairRmaOrder $rma, ?string $actorUserId = null): DepotRepairRmaOrder
    {
        return DB::transaction(function () use ($rma, $actorUserId): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $this->assertStatus($rma, RmaStatus::AwaitingParts, RmaStatus::InRepairTesting->value);
            $rma->parts_required = false;
            $this->transition($rma, RmaStatus::InRepairTesting, RmaStatusHistory::ACTOR_USER, $actorUserId, 'Spare parts received');

            return $this->reload($rma);
        });
    }

    public function completeRepair(DepotRepairRmaOrder $rma, CompleteRmaRepairDTO $dto, ?string $actorUserId = null): DepotRepairRmaOrder
    {
        return DB::transaction(function () use ($rma, $dto, $actorUserId): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $this->assertStatus($rma, RmaStatus::InRepairTesting, RmaStatus::ReadyForReturn->value);

            $passport = $rma->passport;
            if ($passport === null) {
                throw WarrantyRmaException::notFound('passport_missing', 'Equipment passport not found.');
            }

            $now = now();
            foreach ($dto->replacedComponents as $index => $replacement) {
                /** @var EquipmentComponent|null $old */
                $old = $passport->components()
                    ->whereKey($replacement['old_component_id'])
                    ->whereNull('removed_at')
                    ->first();

                if ($old === null) {
                    throw WarrantyRmaException::validation(
                        "replaced_components.{$index}.old_component_id",
                        'The component is not currently installed in this equipment.',
                        'component_not_installed',
                    );
                }

                $new = EquipmentComponent::create([
                    'tenant_id' => $passport->tenant_id,
                    'company_id' => $passport->company_id,
                    'equipment_passport_id' => $passport->id,
                    'position' => $old->position,
                    'component_serial' => $replacement['new_component_serial'],
                    'component_product_id' => $replacement['component_product_id'] ?? $old->component_product_id,
                    'role' => $old->role,
                    'source' => EquipmentComponent::SOURCE_AS_MAINTAINED,
                    'installed_at' => $now,
                    'rma_order_id' => $rma->id,
                ]);

                $old->removed_at = $now;
                $old->replaced_by_component_id = $new->id;
                $old->save();
            }

            if ($dto->installedFirmwareVersion !== null) {
                $passport->installed_firmware_version = $dto->installedFirmwareVersion;
                $passport->save();
            }

            $rma->repair_test_notes = $dto->testNotes;
            $rma->repair_outcome ??= RepairOutcome::Repaired->value;
            $this->transition($rma, RmaStatus::ReadyForReturn, RmaStatusHistory::ACTOR_USER, $actorUserId, 'Repair completed and tested');

            $consumedParts = $rma->lines()
                ->where('line_type', RmaLine::TYPE_PART)
                ->get()
                ->map(static fn (RmaLine $line): array => [
                    'rma_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'qty' => (float) $line->qty,
                    'unit_cost' => (float) $line->unit_cost,
                    'charged_to' => $line->charged_to,
                    'replacement_part_serial' => $line->replacement_part_serial,
                ])
                ->values()
                ->all();

            $this->dispatcher->dispatch(new RmaCompletedEvent(
                rmaOrderId: $rma->id,
                rmaNumber: $rma->rma_number,
                repairOutcome: (string) $rma->repair_outcome,
                warrantyStatus: $rma->warranty_status,
                consumedParts: $consumedParts,
                tenantId: $rma->tenant_id,
                companyId: $this->companyIdOf($rma),
            ));

            return $this->reload($rma);
        });
    }

    public function returnToCustomer(DepotRepairRmaOrder $rma, ReturnRmaDTO $dto, ?string $actorUserId = null): DepotRepairRmaOrder
    {
        return DB::transaction(function () use ($rma, $dto, $actorUserId): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $returnedAt = $dto->returnedAt !== null ? CarbonImmutable::parse($dto->returnedAt) : CarbonImmutable::now();

            $rma->returned_at = $returnedAt;
            $rma->ddt_causale_return = $dto->ddtCausaleReturn ?? $this->defaultReturnCausale($rma);
            if ($dto->vatExemptArt15Dpr633 !== null) {
                $rma->vat_exempt_art15_dpr633 = $dto->vatExemptArt15Dpr633;
            }

            $this->transition($rma, RmaStatus::Returned, RmaStatusHistory::ACTOR_USER, $actorUserId, 'Unit returned to customer');
            $this->closeRepairAccountEntries($rma, $returnedAt);

            return $this->reload($rma);
        });
    }

    public function close(DepotRepairRmaOrder $rma, ?string $actorUserId = null): DepotRepairRmaOrder
    {
        return DB::transaction(function () use ($rma, $actorUserId): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $rma->closed_at = now();
            $this->transition($rma, RmaStatus::Closed, RmaStatusHistory::ACTOR_USER, $actorUserId, 'RMA closed');

            return $this->reload($rma);
        });
    }

    public function cancel(DepotRepairRmaOrder $rma, string $reason, ?string $actorUserId = null): DepotRepairRmaOrder
    {
        return DB::transaction(function () use ($rma, $reason, $actorUserId): DepotRepairRmaOrder {
            $rma = $this->lockFresh($rma);
            $rma->cancelled_at = now();
            $rma->cancellation_reason = $reason;
            $this->transition($rma, RmaStatus::Cancelled, RmaStatusHistory::ACTOR_USER, $actorUserId, $reason);
            $this->closeRepairAccountEntries($rma, CarbonImmutable::now());

            return $this->reload($rma);
        });
    }

    public function regenerateTrackingToken(DepotRepairRmaOrder $rma, ?string $actorUserId = null): string
    {
        $raw = $this->newRawToken();
        $rma->tracking_token_hash = $this->hashToken($raw);
        $rma->save();

        $this->auditLedger->record('RMA_TRACKING_TOKEN_REGENERATED', $rma, null, ['rma_number' => $rma->rma_number], 'MODULE_17_ESM');

        return $raw;
    }

    public function resolveByEstimateToken(string $rawToken): DepotRepairRmaOrder
    {
        $hash = $this->hashToken($rawToken);

        /** @var DepotRepairRmaOrder|null $rma */
        $rma = DepotRepairRmaOrder::withoutGlobalScope(TenantScope::class)
            ->where('estimate_token_hash', $hash)
            ->first();

        if (
            $rma === null
            || $rma->estimate_token_hash === null
            || ! hash_equals($rma->estimate_token_hash, $hash)
            || $rma->estimate_token_expires_at === null
            || $rma->estimate_token_expires_at->isPast()
        ) {
            throw WarrantyRmaException::notFound();
        }

        $this->bindTenant($rma);

        return $rma;
    }

    public function resolveByTrackingToken(string $rmaNumber, string $rawToken): DepotRepairRmaOrder
    {
        $hash = $this->hashToken($rawToken);

        /** @var DepotRepairRmaOrder|null $rma */
        $rma = DepotRepairRmaOrder::withoutGlobalScope(TenantScope::class)
            ->where('tracking_token_hash', $hash)
            ->first();

        if (
            $rma === null
            || $rma->tracking_token_hash === null
            || ! hash_equals($rma->tracking_token_hash, $hash)
            || ! hash_equals($rma->rma_number, trim($rmaNumber))
        ) {
            throw WarrantyRmaException::notFound();
        }

        $this->bindTenant($rma);

        return $rma;
    }

    public function label(DepotRepairRmaOrder $rma, ?string $rawTrackingToken = null): array
    {
        $rma->loadMissing(['passport.product', 'customer']);
        $tenant = Tenant::query()->find($rma->tenant_id);
        $settings = is_array($tenant?->settings) ? $tenant->settings : [];

        $label = [
            'rma_number' => $rma->rma_number,
            'serial_number' => (string) ($rma->passport?->serial_number ?? ''),
            'product_name' => $rma->passport?->product?->name,
            'customer_name' => ContactNameResolver::nameOf($rma->customer),
            'return_address' => $settings['rma']['return_address']
                ?? config('service-management.rma.return_address')
                ?? ($tenant?->legal_name ?: $tenant?->name),
            'barcode_payload' => $rma->rma_number,
            'tracking_url' => $rawTrackingToken !== null ? $this->trackingUrl($rma->rma_number, $rawTrackingToken) : null,
        ];

        if ($rawTrackingToken !== null) {
            $label['tracking_token'] = $rawTrackingToken;
        }

        return $label;
    }

    public function estimateUrl(string $rawToken): string
    {
        return $this->publicBaseUrl().'/rma-estimate/'.$rawToken;
    }

    public function board(array $filters = []): array
    {
        $graceDays = (int) config('service-management.rma.loaner_grace_days', 7);
        $includeClosed = (bool) ($filters['include_closed'] ?? false);
        $staleCutoff = now()->subDays(30);
        $now = now();

        $rmas = DepotRepairRmaOrder::query()
            ->with(['passport:id,serial_number', 'customer', 'loaner.rmaOrder'])
            ->where('status', '!=', RmaStatus::Cancelled->value)
            ->when(! empty($filters['warranty_status']), fn ($query) => $query->where('warranty_status', $filters['warranty_status']))
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(! $includeClosed, fn ($query) => $query->where(function ($inner) use ($staleCutoff): void {
                $inner->whereNotIn('status', [RmaStatus::Returned->value, RmaStatus::Closed->value])
                    ->orWhere('updated_at', '>=', $staleCutoff);
            }))
            ->orderBy('created_at')
            ->get();

        $grouped = $rmas->groupBy('status');

        $columns = [];
        foreach (RmaStatus::boardColumns() as $status) {
            $items = $grouped->get($status->value, collect())
                ->map(function (DepotRepairRmaOrder $rma) use ($now, $graceDays): array {
                    $loaner = $rma->loaner;
                    $hasLoaner = $loaner !== null && $loaner->status === LoanerStatus::Issued->value;

                    return [
                        'id' => $rma->id,
                        'rma_number' => $rma->rma_number,
                        'serial_number' => $rma->passport?->serial_number,
                        'customer_name' => ContactNameResolver::nameOf($rma->customer),
                        'warranty_status' => $rma->warranty_status,
                        'estimate_approval_status' => $rma->estimate_approval_status,
                        'age_days' => $rma->created_at !== null ? (int) floor($rma->created_at->diffInDays($now, true)) : 0,
                        'has_loaner' => $hasLoaner,
                        'loaner_overdue' => $hasLoaner && $loaner->isOverdueAt($now, $graceDays),
                    ];
                })
                ->values()
                ->all();

            $columns[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => count($items),
                'items' => $items,
            ];
        }

        return $columns;
    }

    /**
     * Apply a state machine transition, write the history row and emit RmaStatusChangedEvent. Saves the RMA.
     */
    private function transition(DepotRepairRmaOrder $rma, RmaStatus $to, string $actorType, ?string $actorUserId, ?string $note = null): void
    {
        $from = $this->assertCanTransition($rma, $to);

        $rma->status = $to->value;
        $rma->save();

        $this->writeHistory($rma, $from, $to, $actorType, $actorUserId, $note);

        $this->dispatcher->dispatch(new RmaStatusChangedEvent(
            rmaOrderId: $rma->id,
            rmaNumber: $rma->rma_number,
            fromStatus: $from->value,
            toStatus: $to->value,
            actorType: $actorType,
            tenantId: $rma->tenant_id,
            companyId: $this->companyIdOf($rma),
        ));
    }

    private function assertCanTransition(DepotRepairRmaOrder $rma, RmaStatus $to): RmaStatus
    {
        $from = $rma->statusEnum();

        if (! $from->canTransitionTo($to)) {
            throw WarrantyRmaException::invalidTransition($from->value, $to->value);
        }

        return $from;
    }

    /**
     * Guard for actions that are only valid from one specific status (409 invalid_transition otherwise).
     */
    private function assertStatus(DepotRepairRmaOrder $rma, RmaStatus $expected, string $action): void
    {
        if ($rma->status !== $expected->value) {
            throw WarrantyRmaException::invalidTransition($rma->status, $action);
        }
    }

    private function assertEstimatePending(DepotRepairRmaOrder $rma): void
    {
        if ($rma->estimate_approval_status !== EstimateApprovalStatus::PendingCustomerApproval->value) {
            throw WarrantyRmaException::alreadyDecided();
        }

        if ($rma->status !== RmaStatus::AwaitingEstimateApproval->value) {
            throw WarrantyRmaException::invalidTransition($rma->status, RmaStatus::InRepairTesting->value);
        }
    }

    private function writeHistory(DepotRepairRmaOrder $rma, ?RmaStatus $from, RmaStatus $to, string $actorType, ?string $actorUserId, ?string $note): void
    {
        RmaStatusHistory::create([
            'tenant_id' => $rma->tenant_id,
            'company_id' => $rma->company_id,
            'rma_order_id' => $rma->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actorType === RmaStatusHistory::ACTOR_CUSTOMER ? null : $actorUserId,
            'actor_type' => $actorType,
            'note' => $note,
        ]);
    }

    /**
     * @param  list<RmaLine>  $lines
     */
    private function chargeWarrantyProvision(DepotRepairRmaOrder $rma, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $partsCost = 0.0;
        $labourCost = 0.0;
        $payloadLines = [];
        foreach ($lines as $line) {
            $cost = (float) $line->totalCost();
            if ($line->line_type === RmaLine::TYPE_PART) {
                $partsCost += $cost;
            } else {
                $labourCost += $cost;
            }

            $payloadLines[] = [
                'rma_line_id' => $line->id,
                'line_type' => $line->line_type,
                'product_id' => $line->product_id,
                'qty' => (float) $line->qty,
                'unit_cost' => (float) $line->unit_cost,
                'total_cost' => $cost,
            ];
        }

        $this->dispatcher->dispatch(new RmaWarrantyCostChargedEvent(
            rmaOrderId: $rma->id,
            rmaNumber: $rma->rma_number,
            costBucketCode: (string) config('service-management.rma.cost_bucket_code', 'WARRANTY_PROVISION'),
            partsCost: round($partsCost, 2),
            labourCost: round($labourCost, 2),
            totalCost: round($partsCost + $labourCost, 2),
            currency: $rma->currency,
            lines: $payloadLines,
            tenantId: $rma->tenant_id,
            companyId: $this->companyIdOf($rma),
        ));
    }

    /**
     * Draft a supplier claim-back for every defective part that has an original supplier.
     *
     * @param  list<RmaLine>  $lines
     */
    private function openVendorClaims(DepotRepairRmaOrder $rma, array $lines): void
    {
        foreach ($lines as $line) {
            if ($line->line_type !== RmaLine::TYPE_PART || $line->supplier_id === null) {
                continue;
            }

            $claim = VendorWarrantyClaim::create([
                'tenant_id' => $rma->tenant_id,
                'company_id' => $rma->company_id,
                'rma_order_id' => $rma->id,
                'rma_line_id' => $line->id,
                'original_supplier_id' => $line->supplier_id,
                'defective_part_serial' => $line->defective_part_serial,
                'defective_product_id' => $line->product_id,
                'status' => VendorClaimStatus::Draft->value,
                'claimed_amount' => $line->totalCost(),
                'currency' => $rma->currency,
            ]);

            $this->dispatcher->dispatch(new VendorClaimOpenedEvent(
                claimId: $claim->id,
                rmaOrderId: $rma->id,
                supplierId: $claim->original_supplier_id,
                defectivePartSerial: $claim->defective_part_serial,
                claimedAmount: (float) $claim->claimed_amount,
                currency: $claim->currency,
                tenantId: $rma->tenant_id,
                companyId: $this->companyIdOf($rma),
            ));
        }
    }

    private function emitEstimateDecided(DepotRepairRmaOrder $rma): void
    {
        $this->dispatcher->dispatch(new RmaEstimateDecidedEvent(
            rmaOrderId: $rma->id,
            rmaNumber: $rma->rma_number,
            approvalStatus: $rma->estimate_approval_status,
            decidedVia: (string) $rma->estimate_decided_via,
            estimateTotal: (float) $rma->estimate_total,
            currency: $rma->currency,
            tenantId: $rma->tenant_id,
            companyId: $this->companyIdOf($rma),
        ));
    }

    private function closeRepairAccountEntries(DepotRepairRmaOrder $rma, CarbonImmutable $at): void
    {
        $rma->loaners()
            ->where('register_type', LoanerRegisterType::ContoRiparazione->value)
            ->where('status', LoanerStatus::Issued->value)
            ->get()
            ->each(static function (Loaner $entry) use ($at): void {
                $entry->status = LoanerStatus::Returned->value;
                $entry->returned_at = $at;
                $entry->save();
            });
    }

    /**
     * @param  list<RmaLine>  $lines
     * @return array{0: float, 1: float}
     */
    private function priceTotals(array $lines): array
    {
        $labour = 0.0;
        $parts = 0.0;
        foreach ($lines as $line) {
            $total = (float) $line->totalPrice();
            if ($line->line_type === RmaLine::TYPE_PART) {
                $parts += $total;
            } else {
                $labour += $total;
            }
        }

        return [round($labour, 2), round($parts, 2)];
    }

    private function repairStageFor(bool $partsRequired): RmaStatus
    {
        return $partsRequired ? RmaStatus::AwaitingParts : RmaStatus::InRepairTesting;
    }

    private function actorTypeFor(string $decidedVia): string
    {
        return $decidedVia === self::DECIDED_VIA_PUBLIC_LINK ? RmaStatusHistory::ACTOR_CUSTOMER : RmaStatusHistory::ACTOR_USER;
    }

    private function defaultReturnCausale(DepotRepairRmaOrder $rma): string
    {
        return $rma->repair_outcome === RepairOutcome::Replaced->value
            ? DdtCausale::SostituzioneGaranzia->value
            : DdtCausale::ResoRiparato->value;
    }

    private function lockFresh(DepotRepairRmaOrder $rma): DepotRepairRmaOrder
    {
        /** @var DepotRepairRmaOrder $locked */
        $locked = DepotRepairRmaOrder::query()->whereKey($rma->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function reload(DepotRepairRmaOrder $rma): DepotRepairRmaOrder
    {
        return $rma->fresh(['passport', 'customer', 'lines', 'history', 'vendorClaims', 'loaner.rmaOrder']) ?? $rma;
    }

    private function bindTenant(DepotRepairRmaOrder $rma): void
    {
        $tenant = Tenant::query()->find($rma->tenant_id);
        if ($tenant === null) {
            throw WarrantyRmaException::notFound();
        }

        $this->tenantContext->setTenant($tenant);
    }

    private function companyIdOf(DepotRepairRmaOrder $rma): string
    {
        return (string) ($rma->company_id ?? $rma->tenant_id);
    }

    private function trackingUrl(string $rmaNumber, string $rawToken): string
    {
        return $this->publicBaseUrl().'/rma-tracking?'.http_build_query(['rma' => $rmaNumber, 'token' => $rawToken]);
    }

    private function publicBaseUrl(): string
    {
        return rtrim((string) (config('service-management.rma.public_base_url') ?: config('app.url')), '/');
    }

    private function estimateTtlDays(): int
    {
        return max(1, (int) config('service-management.rma.estimate_token_ttl_days', 30));
    }

    private function newRawToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hashToken(string $rawToken): string
    {
        return hash('sha256', trim($rawToken));
    }

    private function decimal(int|float|string $value, int $scale = 2): string
    {
        return number_format(round((float) $value, $scale), $scale, '.', '');
    }
}
