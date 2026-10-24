<?php

declare(strict_types=1);

namespace App\Actions\FieldService;

use App\Models\WorkOrder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveWorkOrder
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data): WorkOrder {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $workOrder = new WorkOrder;
            $workOrder->fill($data);
            $workOrder->technician_user_id ??= Auth::id();
            $workOrder->work_order_number = $this->numbering->next('work_order', (int) date('Y', strtotime((string) $data['date'])))['number'];
            $workOrder->status = 'draft';
            $workOrder->save();

            $this->syncLines($workOrder, $lines);
            $this->events->record($workOrder, 'created');

            return $workOrder->load('lines');
        });
    }

    /**
     * Update a draft or completed work report; signed and invoiced ones are locked.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(WorkOrder $workOrder, array $data): WorkOrder
    {
        if (in_array($workOrder->status, ['signed', 'invoiced'], true)) {
            throw ValidationException::withMessages(['work_order' => __('work_orders.locked')]);
        }

        return DB::transaction(function () use ($workOrder, $data): WorkOrder {
            $lines = $data['lines'] ?? null;
            unset($data['lines']);

            $workOrder->update($data);

            if ($lines !== null) {
                $this->syncLines($workOrder, $lines);
            }

            $this->events->record($workOrder, 'updated');

            return $workOrder->load('lines');
        });
    }

    /**
     * Replace the lines and recompute labor hours, parts total and net total from them.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(WorkOrder $workOrder, array $lines): void
    {
        $computed = $this->totals->calculate($lines);

        $rows = [];
        foreach ($computed['items'] as $index => $row) {
            $rows[] = array_merge(
                collect($row)->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'line_total'])->all(),
                ['type' => $lines[$index]['type'] ?? 'labor']
            );
        }

        $workOrder->lines()->delete();
        $workOrder->lines()->createMany($rows);

        $workOrder->update([
            'labor_hours' => collect($rows)->where('type', 'labor')->sum('quantity'),
            'parts_total' => collect($rows)->where('type', 'material')->sum('line_total'),
            'total_amount' => $computed['total_net'],
        ]);
    }

    public function complete(WorkOrder $workOrder): WorkOrder
    {
        if ($workOrder->status !== 'draft') {
            throw ValidationException::withMessages(['work_order' => __('work_orders.not_completable')]);
        }

        $workOrder->update(['status' => 'completed', 'ended_at' => $workOrder->ended_at ?? now()]);
        $this->events->record($workOrder, 'completed');

        return $workOrder;
    }
}
