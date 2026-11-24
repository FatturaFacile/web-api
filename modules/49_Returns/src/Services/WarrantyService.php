<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\Warranty;
use Modules\Returns\Models\WarrantyClaim;
use Modules\Returns\Support\ReturnsError;

/**
 * The warranty registry and its claims. A warranty covers a unit (serial number) or a batch (lot number) of a product
 * sold to a customer for a period. A claim freezes whether the warranty was valid on the claim date; accepting a claim
 * the warranty does not cover needs an approver's override.
 */
final readonly class WarrantyService
{
    public function __construct(private ReturnsNumbering $numbering, private ReturnsLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): Warranty
    {
        $this->assertReferences($tenantId, $data);
        $this->assertPeriod((string) $data['starts_on'], (string) $data['ends_on']);
        $this->assertIdentifier($data['serial_number'] ?? null, $data['lot_number'] ?? null);
        $this->assertSerialFree($tenantId, (string) $data['product_id'], $data['serial_number'] ?? null, null);

        $warranty = Warranty::create([
            'tenant_id' => $tenantId,
            'product_id' => $data['product_id'],
            'contact_id' => $data['contact_id'],
            'invoice_id' => $data['invoice_id'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'lot_number' => $data['lot_number'] ?? null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'coverage_type' => $data['coverage_type'] ?? 'full',
            'coverage_terms' => $data['coverage_terms'] ?? null,
            'status' => Warranty::Active,
        ]);
        $this->ledger->record('returns.warranty_registered', $warranty, ['product_id' => $warranty->product_id]);

        return $warranty;
    }

    /**
     * The product, the customer and the unit are fixed: a wrong one is voided and registered again.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Warranty $warranty, array $data): Warranty
    {
        if ($warranty->status === Warranty::Void) {
            throw ReturnsError::on('status', 'warranty.void_locked');
        }

        $this->assertPeriod((string) ($data['starts_on'] ?? $warranty->starts_on->toDateString()), (string) ($data['ends_on'] ?? $warranty->ends_on->toDateString()));
        $warranty->update(array_intersect_key($data, array_flip(['starts_on', 'ends_on', 'coverage_type', 'coverage_terms'])));

        return $warranty->refresh();
    }

    public function void(Warranty $warranty, string $reason): Warranty
    {
        if ($warranty->status === Warranty::Void) {
            throw ReturnsError::on('status', 'warranty.void_locked');
        }

        $warranty->update(['status' => Warranty::Void, 'void_reason' => $reason]);
        $this->ledger->record('returns.warranty_voided', $warranty);

        return $warranty->refresh();
    }

    /**
     * Whether the warranty covers a claim on the date.
     *
     * @return array{valid: bool, reason: string|null, checked_on: string, days_left: int}
     */
    public function validity(Warranty $warranty, ?string $on = null): array
    {
        $date = Carbon::parse($on ?? now()->toDateString())->startOfDay();
        $reason = match (true) {
            $warranty->status === Warranty::Void => 'void',
            $date->lt($warranty->starts_on->copy()->startOfDay()) => 'not_started',
            $date->gt($warranty->ends_on->copy()->startOfDay()) => 'expired',
            default => null,
        };

        return [
            'valid' => $reason === null,
            'reason' => $reason,
            'checked_on' => $date->toDateString(),
            'days_left' => $reason === null ? (int) $date->diffInDays($warranty->ends_on->copy()->startOfDay()) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createClaim(string $tenantId, array $data, ?string $userId): WarrantyClaim
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): WarrantyClaim {
            $warranty = Warranty::query()->where('tenant_id', $tenantId)->whereKey($data['warranty_id'])->first();

            if ($warranty === null) {
                throw ReturnsError::on('warranty_id', 'claim.warranty_unknown');
            }

            if (! empty($data['rma_id'])) {
                $rma = Rma::query()->where('tenant_id', $tenantId)->whereKey($data['rma_id'])->first();

                if ($rma === null || (string) $rma->contact_id !== (string) $warranty->contact_id) {
                    throw ReturnsError::on('rma_id', 'claim.rma_mismatch');
                }
            }

            $claimDate = (string) ($data['claim_date'] ?? now()->toDateString());
            $validity = $this->validity($warranty, $claimDate);
            $claim = WarrantyClaim::create([
                'tenant_id' => $tenantId,
                'claim_number' => $this->numbering->next(WarrantyClaim::class, 'claim_number', 'CLM', $tenantId),
                'warranty_id' => $warranty->id,
                'rma_id' => $data['rma_id'] ?? null,
                'claim_date' => $claimDate,
                'description' => $data['description'],
                'status' => WarrantyClaim::Open,
                'is_valid' => $validity['valid'],
                'validity_note' => $validity['reason'],
                'created_by' => $userId,
            ]);
            $this->ledger->record('returns.claim_raised', $claim, ['is_valid' => $claim->is_valid]);

            return $claim;
        });
    }

    public function accept(WarrantyClaim $claim, ?string $notes, bool $override): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $notes, $override): WarrantyClaim {
            $locked = $this->lockClaim($claim, WarrantyClaim::Open);

            if (! $locked->is_valid && ! $override) {
                throw ReturnsError::on('status', 'claim.not_valid', ['reason' => (string) $locked->validity_note]);
            }

            $locked->update(['status' => WarrantyClaim::Accepted, 'decision_notes' => $notes, 'decided_at' => now()]);
            $this->ledger->record('returns.claim_accepted', $locked, ['is_valid' => $locked->is_valid]);

            return $locked->refresh();
        });
    }

    public function reject(WarrantyClaim $claim, string $notes): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $notes): WarrantyClaim {
            $locked = $this->lockClaim($claim, WarrantyClaim::Open);
            $locked->update(['status' => WarrantyClaim::Rejected, 'decision_notes' => $notes, 'decided_at' => now()]);
            $this->ledger->record('returns.claim_rejected', $locked);

            return $locked->refresh();
        });
    }

    public function resolve(WarrantyClaim $claim): WarrantyClaim
    {
        return DB::transaction(function () use ($claim): WarrantyClaim {
            $locked = $this->lockClaim($claim, WarrantyClaim::Accepted);
            $locked->update(['status' => WarrantyClaim::Resolved, 'resolved_at' => now()]);
            $this->ledger->record('returns.claim_resolved', $locked);

            return $locked->refresh();
        });
    }

    private function lockClaim(WarrantyClaim $claim, string $expected): WarrantyClaim
    {
        $locked = WarrantyClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== $expected) {
            throw ReturnsError::on('status', 'claim.status_expected', ['status' => $locked->status, 'expected' => $expected]);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertReferences(string $tenantId, array $data): void
    {
        if (! Product::withTrashed()->where('tenant_id', $tenantId)->whereKey($data['product_id'])->exists()) {
            throw ReturnsError::on('product_id', 'warranty.product_unknown');
        }

        if (! Contact::query()->where('tenant_id', $tenantId)->whereKey($data['contact_id'])->exists()) {
            throw ReturnsError::on('contact_id', 'rma.customer_unknown');
        }

        if (! empty($data['invoice_id']) && ! Invoice::query()->where('tenant_id', $tenantId)->where('contact_id', $data['contact_id'])->whereKey($data['invoice_id'])->exists()) {
            throw ReturnsError::on('invoice_id', 'rma.invoice_not_returnable');
        }
    }

    private function assertPeriod(string $startsOn, string $endsOn): void
    {
        if (Carbon::parse($endsOn)->lt(Carbon::parse($startsOn))) {
            throw ReturnsError::on('ends_on', 'warranty.period_invalid');
        }
    }

    private function assertIdentifier(?string $serial, ?string $lot): void
    {
        if (($serial === null || $serial === '') && ($lot === null || $lot === '')) {
            throw ReturnsError::on('serial_number', 'warranty.identifier_required');
        }
    }

    private function assertSerialFree(string $tenantId, string $productId, ?string $serial, ?string $exceptId): void
    {
        if ($serial === null || $serial === '') {
            return;
        }

        $taken = Warranty::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('serial_number', $serial)
            ->where('status', Warranty::Active)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ReturnsError::on('serial_number', 'warranty.serial_taken', ['serial' => $serial]);
        }
    }
}
