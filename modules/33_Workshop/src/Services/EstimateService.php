<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Vehicle;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Workshop\Models\WorkshopEstimate;
use Modules\Workshop\Models\WorkshopEstimateLine;
use Modules\Workshop\Models\WorkshopFolder;

/**
 * The estimate workflow of a workshop: draft with labour and part lines, sent to the customer, approved or rejected, and
 * an approved estimate converted into a work folder carrying the same lines. Lines change only while the estimate is a
 * draft; totals are recalculated with bcmath after every change.
 */
final class EstimateService
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly WorkshopService $workshop,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Creates a draft estimate for a vehicle, with its lines when given.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WorkshopEstimate
    {
        return DB::transaction(function () use ($data): WorkshopEstimate {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $estimate = new WorkshopEstimate;
            $estimate->fill([
                ...$data,
                'contact_id' => $data['contact_id'] ?? $vehicle->client_id,
                'estimate_number' => $this->numbering->next('workshop_estimate')['number'],
                'status' => 'draft',
            ]);
            $estimate->save();

            foreach ($lines as $line) {
                $this->insertLine($estimate, $line);
            }

            return $this->recalculate($estimate);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkshopEstimate $estimate, array $data): WorkshopEstimate
    {
        return DB::transaction(function () use ($estimate, $data): WorkshopEstimate {
            $locked = $this->lock($estimate);
            $this->assertDraft($locked);
            unset($data['lines']);
            $locked->update($data);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addLine(WorkshopEstimate $estimate, array $data): WorkshopEstimateLine
    {
        return DB::transaction(function () use ($estimate, $data): WorkshopEstimateLine {
            $locked = $this->lock($estimate);
            $this->assertDraft($locked);
            $line = $this->insertLine($locked, $data);
            $this->recalculate($locked);

            return $line;
        });
    }

    public function removeLine(WorkshopEstimate $estimate, WorkshopEstimateLine $line): void
    {
        DB::transaction(function () use ($estimate, $line): void {
            $locked = $this->lock($estimate);
            $this->assertDraft($locked);

            if ($line->estimate_id !== $locked->id) {
                throw new BusinessRuleException('The line belongs to another estimate.');
            }

            $line->delete();
            $this->recalculate($locked);
        });
    }

    /**
     * Marks the estimate as sent to the customer. Only a draft with lines is sent.
     */
    public function send(WorkshopEstimate $estimate): WorkshopEstimate
    {
        return DB::transaction(function () use ($estimate): WorkshopEstimate {
            $locked = $this->lock($estimate);
            $this->assertTransition($locked, 'sent');

            if (! $locked->lines()->exists()) {
                throw new BusinessRuleException('The estimate has no lines.');
            }

            $locked->update(['status' => 'sent', 'sent_at' => now()]);

            return $locked;
        });
    }

    /**
     * Records the approval of the customer; an estimate past its validity date can no longer be approved.
     */
    public function approve(WorkshopEstimate $estimate, ?string $note): WorkshopEstimate
    {
        return DB::transaction(function () use ($estimate, $note): WorkshopEstimate {
            $locked = $this->lock($estimate);
            $this->assertTransition($locked, 'approved');

            if ($locked->valid_until !== null && $locked->valid_until->toDateString() < $this->clock->today()->toDateString()) {
                throw new BusinessRuleException('The estimate has expired.');
            }

            $locked->update(['status' => 'approved', 'decided_at' => now(), 'decision_note' => $note]);

            return $locked;
        });
    }

    /**
     * Records the rejection of the customer with its reason.
     */
    public function reject(WorkshopEstimate $estimate, ?string $reason): WorkshopEstimate
    {
        return DB::transaction(function () use ($estimate, $reason): WorkshopEstimate {
            $locked = $this->lock($estimate);
            $this->assertTransition($locked, 'rejected');
            $locked->update(['status' => 'rejected', 'decided_at' => now(), 'decision_note' => $reason]);

            return $locked;
        });
    }

    /**
     * Opens a work folder from an approved estimate, with the same lines, and closes the estimate as converted.
     */
    public function convert(WorkshopEstimate $estimate): WorkshopFolder
    {
        return DB::transaction(function () use ($estimate): WorkshopFolder {
            $locked = $this->lock($estimate);

            if ($locked->folder_id !== null) {
                throw new BusinessRuleException('The estimate is already converted.');
            }

            $this->assertTransition($locked, 'converted');

            $folder = $this->workshop->openFolder([
                'vehicle_id' => $locked->vehicle_id,
                'contact_id' => $locked->contact_id,
                'kind' => $locked->kind,
                'currency' => $locked->currency,
                'mileage_in' => $locked->mileage_in,
                'complaint' => $locked->complaint,
                'diagnosis' => $locked->diagnosis,
            ]);

            foreach ($locked->lines()->get() as $line) {
                $this->workshop->addLine($folder, [
                    'line_type' => $line->line_type,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'discount_percentage' => (string) $line->discount_percentage,
                    'tax_rate' => (string) $line->tax_rate,
                ]);
            }

            $locked->update(['status' => 'converted', 'folder_id' => $folder->id]);

            return $folder->load('lines');
        });
    }

    /**
     * Deletes a draft estimate; a sent or decided one stays as the record of what was offered.
     */
    public function delete(WorkshopEstimate $estimate): void
    {
        DB::transaction(function () use ($estimate): void {
            $locked = $this->lock($estimate);
            $this->assertDraft($locked);
            $locked->lines()->delete();
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertLine(WorkshopEstimate $estimate, array $data): WorkshopEstimateLine
    {
        $discount = (string) ($data['discount_percentage'] ?? '0');

        $line = new WorkshopEstimateLine;
        $line->fill([
            'estimate_id' => $estimate->id,
            'line_type' => $data['line_type'],
            'product_id' => $data['product_id'] ?? null,
            'description' => $data['description'],
            'quantity' => (string) $data['quantity'],
            'unit_price' => (string) $data['unit_price'],
            'discount_percentage' => $discount,
            'tax_rate' => (string) $data['tax_rate'],
            'line_total' => $this->workshop->lineTotal((string) $data['quantity'], (string) $data['unit_price'], $discount),
            'sort_order' => (int) $estimate->lines()->max('sort_order') + 1,
        ]);
        $line->save();

        return $line;
    }

    private function recalculate(WorkshopEstimate $estimate): WorkshopEstimate
    {
        $subtotal = '0';
        $tax = '0';

        foreach ($estimate->lines()->get() as $line) {
            $subtotal = Decimal::add($subtotal, (string) $line->line_total);
            $tax = Decimal::add($tax, Decimal::round2(Decimal::div(Decimal::mul((string) $line->line_total, (string) $line->tax_rate), '100')));
        }

        $estimate->update([
            'subtotal' => Decimal::round2($subtotal),
            'tax_total' => Decimal::round2($tax),
            'total' => Decimal::round2(Decimal::add($subtotal, $tax)),
        ]);

        return $estimate;
    }

    private function lock(WorkshopEstimate $estimate): WorkshopEstimate
    {
        return WorkshopEstimate::query()->lockForUpdate()->findOrFail($estimate->id);
    }

    private function assertDraft(WorkshopEstimate $estimate): void
    {
        if ($estimate->status !== 'draft') {
            throw new BusinessRuleException("A {$estimate->status} estimate cannot change.");
        }
    }

    private function assertTransition(WorkshopEstimate $estimate, string $target): void
    {
        if (! in_array($target, WorkshopEstimate::Transitions[$estimate->status] ?? [], true)) {
            throw new BusinessRuleException("An estimate cannot move from {$estimate->status} to {$target}.");
        }
    }
}
