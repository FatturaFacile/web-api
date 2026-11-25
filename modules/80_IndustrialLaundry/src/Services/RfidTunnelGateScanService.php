<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Application\Commands\ProcessRfidTunnelGateScanCommand;
use Modules\IndustrialLaundry\Compliance\ComplianceStrategyRegistry;
use Modules\IndustrialLaundry\Contracts\LaundryDeliveryNoteWriterInterface;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\RollCage;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Models\WashBatch;
use Modules\IndustrialLaundry\Support\Money;

/**
 * Processes a roll cage passing a tunnel gate: filters the reads of the UHF reader, weighs the cage, moves every garment through
 * its lifecycle (soiled on the way in, delivered clean on the way out, a wash cycle counted when a wash was completed), stops the
 * PPE garments that reached their certified wash limit and draws up the delivery note (DDT).
 */
final class RfidTunnelGateScanService
{
    public function __construct(
        private readonly TagReadFilter $filter,
        private readonly RollWeightCalculator $weights,
        private readonly GarmentLifecycleService $lifecycle,
        private readonly ComplianceStrategyRegistry $compliance,
        private readonly LaundryDeliveryNoteWriterInterface $notes,
    ) {}

    public function process(ProcessRfidTunnelGateScanCommand $command): ScanSession
    {
        if ($command->externalScanId !== null) {
            $existing = ScanSession::query()->where('external_scan_id', $command->externalScanId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $direction = ScanDirection::tryFrom($command->direction) ?? throw new IndustrialLaundryException('invalid_direction');
        $at = $command->scannedAt !== null ? Carbon::parse($command->scannedAt) : Carbon::now();
        $filtered = $this->filter->filter($command->reads, $command->gateAntennaId);

        return DB::transaction(fn (): ScanSession => $this->run($command, $direction, $at, $filtered));
    }

    /**
     * @param  array{accepted: list<array{epc: string, rssi: float|null, reads: int}>, rejected: list<array{epc: string, reason: string, rssi: float|null}>}  $filtered
     */
    private function run(ProcessRfidTunnelGateScanCommand $command, ScanDirection $direction, Carbon $at, array $filtered): ScanSession
    {
        $cage = $this->resolveCage($command);
        $net = $command->grossWeightKg !== null ? $this->weights->net($command->grossWeightKg, (string) $cage->tare_weight_kg, $cage->max_gross_weight_kg !== null ? (string) $cage->max_gross_weight_kg : null) : null;
        $epcs = array_column($filtered['accepted'], 'epc');

        /** @var Collection<int, Garment> $found */
        $found = $epcs === [] ? new Collection : Garment::query()->whereIn('epc_uid_rfid_hex', $epcs)->get();
        $garments = $found->keyBy('epc_uid_rfid_hex');
        $rejected = $filtered['rejected'];

        foreach ($epcs as $epc) {
            if (! $garments->has($epc)) {
                $rejected[] = ['epc' => $epc, 'reason' => 'unknown_tag', 'rssi' => null];
            }
        }

        $contactId = $this->resolveContact($command->contactId, $garments);

        $session = ScanSession::query()->create([
            'tenant_id' => $command->tenantId,
            'external_scan_id' => $command->externalScanId,
            'gate_antenna_id' => $command->gateAntennaId,
            'roll_cage_id' => $cage->id,
            'roll_cage_barcode' => $cage->barcode,
            'roll_cage_tare_weight_kg' => $cage->tare_weight_kg,
            'gross_weight_kg' => $command->grossWeightKg !== null ? Money::weight($command->grossWeightKg) : null,
            'net_weight_kg' => $net,
            'direction' => $direction->value,
            'contact_id' => $contactId,
            'raw_reads_count' => count($command->reads),
            'scanned_at' => $at,
            'created_by' => $command->userId,
        ]);

        $alerts = [];
        $processed = [];
        $context = ['scan_session_id' => (string) $session->id, 'contact_id' => $contactId, 'userId' => $command->userId];

        foreach ($garments->sortKeys() as $epc => $garment) {
            $reason = $this->handleGarment($garment, $direction, $contactId, $at, $context, $alerts);

            if ($reason === null) {
                $processed[$epc] = $garment;

                continue;
            }

            $rejected[] = ['epc' => (string) $epc, 'reason' => $reason, 'rssi' => null];
        }

        $pieces = [];

        foreach ($processed as $garment) {
            $pieces[$garment->article_type->value] = ($pieces[$garment->article_type->value] ?? 0) + 1;
        }

        ksort($pieces);
        $ddt = $this->draftDeliveryNote($command, $direction, $contactId, $pieces, $session, $net, $at, $alerts);

        $cage->forceFill(['last_scanned_at' => $at, 'holder_contact_id' => $direction === ScanDirection::OutboundClean ? $contactId : null])->save();

        $session->forceFill([
            'read_epc_tags_list' => ['raw' => $command->reads, 'deduplicated' => $epcs],
            'accepted_epc_tags' => array_keys($processed),
            'rejected_reads' => $rejected,
            'alerts' => $alerts,
            'accepted_count' => count($processed),
            'rejected_count' => count($rejected),
            'generated_ddt_id' => $ddt['id'] ?? null,
            'generated_ddt_number' => $ddt['number'] ?? null,
        ])->save();

        return $session;
    }

    private function resolveCage(ProcessRfidTunnelGateScanCommand $command): RollCage
    {
        $cage = RollCage::query()->where('barcode', $command->rollCageBarcode)->first();

        if ($cage !== null) {
            if (! $cage->is_active) {
                throw new IndustrialLaundryException('roll_cage_inactive');
            }

            if (Money::isZero((string) $cage->tare_weight_kg) && $command->rollCageTareWeightKg !== null) {
                $cage->forceFill(['tare_weight_kg' => Money::weight($command->rollCageTareWeightKg), 'updated_by' => $command->userId])->save();
            }

            return $cage;
        }

        if ($command->rollCageTareWeightKg === null && $command->grossWeightKg !== null) {
            throw new IndustrialLaundryException('roll_cage_unknown', 422, ['barcode' => $command->rollCageBarcode]);
        }

        return RollCage::query()->create([
            'tenant_id' => $command->tenantId,
            'barcode' => $command->rollCageBarcode,
            'tare_weight_kg' => Money::weight($command->rollCageTareWeightKg ?? '0'),
            'is_active' => true,
            'created_by' => $command->userId,
        ]);
    }

    /**
     * The customer the cage belongs to: the one the operator chose, else the one most of the tagged garments are assigned to.
     *
     * @param  Collection<string, Garment>  $garments
     */
    private function resolveContact(?string $contactId, Collection $garments): ?string
    {
        if ($contactId !== null) {
            if (! Contact::query()->whereKey($contactId)->exists()) {
                throw new IndustrialLaundryException('contact_not_found');
            }

            return $contactId;
        }

        $counts = [];

        foreach ($garments as $garment) {
            if ($garment->assigned_customer_id !== null) {
                $counts[$garment->assigned_customer_id] = ($counts[$garment->assigned_customer_id] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        ksort($counts);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  array{scan_session_id: string, contact_id: string|null, userId: string|null}  $context
     * @param  list<array<string, mixed>>  $alerts
     * @return string|null The reason the garment was refused, null when it was processed.
     */
    private function handleGarment(Garment $garment, ScanDirection $direction, ?string $contactId, Carbon $at, array $context, array &$alerts): ?string
    {
        $state = $garment->current_lifecycle_state;

        if ($state === LifecycleState::CondemnedRag) {
            return 'condemned';
        }

        if ($state === LifecycleState::InMending) {
            return 'in_mending';
        }

        if ($garment->assigned_customer_id !== null && $contactId !== null && $garment->assigned_customer_id !== $contactId) {
            return 'customer_mismatch';
        }

        if ($contactId !== null && $garment->assigned_customer_id === null) {
            $garment->assigned_customer_id = $contactId;
        }

        return $direction === ScanDirection::InboundSoil
            ? $this->receiveSoiled($garment, $state, $at, $context)
            : $this->deliverClean($garment, $state, $at, $context, $alerts);
    }

    /**
     * @param  array{scan_session_id: string, contact_id: string|null, userId: string|null}  $context
     */
    private function receiveSoiled(Garment $garment, LifecycleState $state, Carbon $at, array $context): ?string
    {
        if ($state === LifecycleState::SoiledReturned || $state === LifecycleState::InWashTunnel) {
            return 'already_in_laundry';
        }

        $this->lifecycle->move($garment, LifecycleState::SoiledReturned, GarmentEventType::ReceivedSoiled, $at, $context);

        return null;
    }

    /**
     * @param  array{scan_session_id: string, contact_id: string|null, userId: string|null}  $context
     * @param  list<array<string, mixed>>  $alerts
     */
    private function deliverClean(Garment $garment, LifecycleState $state, Carbon $at, array $context, array &$alerts): ?string
    {
        if ($state === LifecycleState::DeliveredToCustomer) {
            return 'already_delivered';
        }

        if ($garment->is_reissue_blocked) {
            return 'reissue_blocked';
        }

        if ($state === LifecycleState::InWashTunnel && ! $this->batchIsCertified($garment)) {
            return 'rabc_not_certified';
        }

        $washCompleted = $state->completesWash();
        $findings = $this->compliance->garmentFindings($garment, $garment->wash_cycles_count + ($washCompleted ? 1 : 0));

        foreach ($findings as $finding) {
            $alerts[] = ['epc' => $garment->epc_uid_rfid_hex, 'garment_id' => (string) $garment->id] + $finding->toArray();
        }

        if (ComplianceStrategyRegistry::blocks($findings)) {
            $garment->is_reissue_blocked = true;
            $this->lifecycle->move($garment, LifecycleState::InMending, GarmentEventType::PpeBlocked, $at, $context + ['count_wash' => $washCompleted, 'reason' => 'ppe_wash_limit_reached']);

            return 'ppe_wash_limit_reached';
        }

        $this->lifecycle->move($garment, LifecycleState::DeliveredToCustomer, GarmentEventType::DeliveredClean, $at, $context + ['count_wash' => $washCompleted]);

        return null;
    }

    /**
     * Linen in the wash tunnel leaves only with the RABC certificate of the batch it was washed in (UNI EN 14065 traceability).
     */
    private function batchIsCertified(Garment $garment): bool
    {
        $batch = WashBatch::query()->whereHas('garments', fn ($query) => $query->whereKey($garment->getKey()))->latest('created_at')->first();

        return $batch === null || $batch->rabc_control_status === CertificationStatus::Compliant;
    }

    /**
     * @param  array<string, int>  $pieces
     * @param  list<array<string, mixed>>  $alerts
     * @return array{id?: string, number?: string}
     */
    private function draftDeliveryNote(ProcessRfidTunnelGateScanCommand $command, ScanDirection $direction, ?string $contactId, array $pieces, ScanSession $session, ?string $net, Carbon $at, array &$alerts): array
    {
        if (! $command->generateDdt || $pieces === []) {
            return [];
        }

        if ($contactId === null) {
            $alerts[] = ['code' => 'customer_unresolved', 'severity' => 'warning', 'params' => []];

            return [];
        }

        return $this->notes->write($command->tenantId, $contactId, $direction, $pieces, $session->gross_weight_kg !== null ? (string) $session->gross_weight_kg : null, $net, (string) $session->roll_cage_barcode, $at, $command->userId);
    }
}
