<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\QualityTest;
use Modules\DairyCheeseManufacturing\Models\RecallReport;
use Modules\DairyCheeseManufacturing\Models\VatBatchMilkDraw;

/**
 * HACCP bidirectional traceability. Forward from a delivery or a laboratory result: every tank, vat batch, wheel and customer
 * reached by that milk. Backward from a wheel (by id or casein plaque serial) or a vat batch: every vat, tank, delivery,
 * laboratory result and farmer that contributed to it. The recall report lists what is affected and can put the wheels on recall.
 *
 * A node is {type, id, label, meta, children}; the types are farmer, delivery, test, tank, batch, wheel and customer.
 */
final class TraceabilityService
{
    /**
     * @return array{root: array<string, mixed>, summary: array<string, int|string>}
     */
    public function trace(string $direction, string $type, ?string $id, ?string $serial = null): array
    {
        $id = $this->resolveId($type, $id, $serial);
        $ids = ['farmer' => [], 'delivery' => [], 'tank' => [], 'batch' => [], 'wheel' => [], 'customer' => []];
        $wheelKg = 0.0;

        $root = $direction === 'forward' ? $this->forward($type, $id) : $this->backward($type, $id);
        $this->collect($root, $ids, $wheelKg);

        return [
            'root' => $root,
            'summary' => [
                'farmers' => count($ids['farmer']), 'deliveries' => count($ids['delivery']), 'tanks' => count($ids['tank']), 'batches' => count($ids['batch']),
                'wheels' => count($ids['wheel']), 'customers' => count($ids['customer']), 'wheels_kg' => number_format($wheelKg, 3, '.', ''),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recall(string $type, ?string $id, ?string $serial, string $reason, bool $apply, ?string $userId = null): array
    {
        $id = $this->resolveId($type, $id, $serial);
        $trace = $this->trace('forward', $type, $id);

        $wheelIds = [];
        $batchIds = [];
        $deliveryIds = [];
        $this->flatten($trace['root'], $wheelIds, $batchIds, $deliveryIds);

        return DB::transaction(function () use ($type, $id, $reason, $apply, $wheelIds, $batchIds, $deliveryIds, $trace, $userId): array {
            $wheels = CheeseAgingWheel::query()->with(['batch', 'rack'])->whereIn('id', $wheelIds)->get();
            $batches = CheeseVatBatch::query()->with(['tankUsages.tank', 'draws.breeder'])->whereIn('id', $batchIds)->get();

            $customers = [];

            foreach ($wheels->filter(static fn (CheeseAgingWheel $w): bool => $w->customer_name !== null) as $wheel) {
                $name = (string) $wheel->customer_name;
                $customers[$name]['name'] = $name;
                $customers[$name]['wheels'] = ($customers[$name]['wheels'] ?? 0) + 1;
                $customers[$name]['kg'] = round(($customers[$name]['kg'] ?? 0.0) + (float) $wheel->sold_weight_kg, 3);
            }

            if ($apply) {
                CheeseAgingWheel::query()->whereIn('id', $wheelIds)->update(['status' => 'recalled']);
                MilkDelivery::query()->whereIn('id', $deliveryIds)->whereIn('status', ['registered', 'accepted'])->update(['status' => 'quarantined']);
                $wheels = CheeseAgingWheel::query()->with(['batch', 'rack'])->whereIn('id', $wheelIds)->get();
            }

            $report = RecallReport::query()->create([
                'source_type' => $type, 'source_id' => $id, 'reason' => $reason, 'applied' => $apply, 'generated_at' => now(), 'created_by' => $userId,
                'payload' => ['summary' => $trace['summary'], 'wheel_ids' => $wheelIds, 'batch_ids' => $batchIds],
            ]);

            return [
                'report' => $report, 'summary' => $trace['summary'], 'wheels' => $wheels, 'batches' => $batches,
                'customers' => array_values(array_map(static fn (array $c): array => ['name' => $c['name'], 'wheels' => $c['wheels'], 'kg' => number_format($c['kg'], 3, '.', '')], $customers)),
            ];
        });
    }

    private function resolveId(string $type, ?string $id, ?string $serial): string
    {
        if ($type === 'wheel' && ($id === null || $id === '') && $serial !== null && $serial !== '') {
            $wheel = CheeseAgingWheel::query()->where('serial', $serial)->first();

            if ($wheel === null) {
                throw new DairyCheeseException('No wheel has that casein plaque.', 'trace_not_found', 404);
            }

            return $wheel->id;
        }

        if ($id === null || $id === '') {
            throw new DairyCheeseException('The starting point of the trace is required.', 'trace_not_found', 404);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function forward(string $type, string $id): array
    {
        return match ($type) {
            'delivery' => $this->deliveryNode(MilkDelivery::query()->with(['breeder', 'tank'])->find($id) ?? $this->notFound(), true),
            'test' => $this->testForward(QualityTest::query()->with('breeder')->find($id) ?? $this->notFound()),
            'batch' => $this->batchNode(CheeseVatBatch::query()->find($id) ?? $this->notFound(), true, false),
            'wheel' => $this->wheelNode(CheeseAgingWheel::query()->with('batch')->find($id) ?? $this->notFound(), true, false),
            default => throw new DairyCheeseException('The trace type is not valid.', 'trace_type_invalid'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function backward(string $type, string $id): array
    {
        return match ($type) {
            'wheel' => $this->wheelNode(CheeseAgingWheel::query()->with('batch')->find($id) ?? $this->notFound(), false, true),
            'batch' => $this->batchNode(CheeseVatBatch::query()->find($id) ?? $this->notFound(), false, true),
            'delivery' => $this->deliveryNode(MilkDelivery::query()->with(['breeder', 'tank'])->find($id) ?? $this->notFound(), false),
            'test' => $this->testBackward(QualityTest::query()->with('breeder')->find($id) ?? $this->notFound()),
            default => throw new DairyCheeseException('The trace type is not valid.', 'trace_type_invalid'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryNode(MilkDelivery $delivery, bool $downstream): array
    {
        $children = [];

        if ($downstream) {
            $draws = VatBatchMilkDraw::query()->with(['batch', 'tank'])->where('delivery_id', $delivery->id)->get();

            foreach ($draws->groupBy('vat_batch_id') as $batchDraws) {
                $node = $this->batchNode($batchDraws->first()->batch, true, false);
                $node['meta']['from_delivery_kg'] = number_format((float) $batchDraws->sum('kg'), 3, '.', '');
                $tank = $batchDraws->first()->tank;
                $children[] = ['type' => 'tank', 'id' => $tank->id, 'label' => $tank->code, 'meta' => ['name' => $tank->name], 'children' => [$node]];
            }
        } else {
            $children[] = $this->farmerNode($delivery->breeder);
            $tests = QualityTest::query()->where('breeder_id', $delivery->breeder_id)->where(static fn ($q) => $q->where('delivery_id', $delivery->id)->orWhereDate('sample_date', $delivery->collection_date->toDateString()))->get();

            foreach ($tests as $test) {
                $children[] = $this->testLeaf($test);
            }
        }

        return [
            'type' => 'delivery', 'id' => $delivery->id,
            'label' => $delivery->breeder->name.' · '.$delivery->collection_date->toDateString().' '.$delivery->session,
            'meta' => ['kg' => (string) $delivery->kg, 'liters' => (string) $delivery->liters, 'tank_code' => $delivery->tank->code, 'status' => $delivery->status],
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testForward(QualityTest $test): array
    {
        $deliveries = MilkDelivery::query()->with(['breeder', 'tank'])->where('breeder_id', $test->breeder_id)
            ->whereBetween('collection_date', [($test->period_start ?? $test->sample_date)->toDateString(), ($test->period_end ?? $test->sample_date)->toDateString()])->orderBy('collection_date')->get();

        return array_merge($this->testLeaf($test), ['children' => $deliveries->map(fn (MilkDelivery $d): array => $this->deliveryNode($d, true))->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function testBackward(QualityTest $test): array
    {
        return array_merge($this->testLeaf($test), ['children' => [$this->farmerNode($test->breeder)]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function batchNode(CheeseVatBatch $batch, bool $downstream, bool $upstream): array
    {
        $children = [];

        if ($downstream) {
            foreach (CheeseAgingWheel::query()->with('batch')->where('vat_batch_id', $batch->id)->orderBy('serial')->get() as $wheel) {
                $children[] = $this->wheelNode($wheel, true, false);
            }
        }

        if ($upstream) {
            $draws = VatBatchMilkDraw::query()->with(['tank', 'delivery.breeder'])->where('vat_batch_id', $batch->id)->get();

            foreach ($draws->groupBy('tank_id') as $tankDraws) {
                $tank = $tankDraws->first()->tank;
                $deliveryNodes = [];

                foreach ($tankDraws as $draw) {
                    $node = $this->deliveryNode($draw->delivery, false);
                    $node['meta']['from_delivery_kg'] = (string) $draw->kg;
                    $deliveryNodes[] = $node;
                }

                $children[] = ['type' => 'tank', 'id' => $tank->id, 'label' => $tank->code, 'meta' => ['name' => $tank->name], 'children' => $deliveryNodes];
            }
        }

        return [
            'type' => 'batch', 'id' => $batch->id, 'label' => $batch->code,
            'meta' => ['cheese_type' => $batch->cheese_type, 'production_date' => $batch->production_date->toDateString(), 'milk_kg' => (string) $batch->milk_kg, 'wheels_count' => (int) $batch->wheels_count],
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wheelNode(CheeseAgingWheel $wheel, bool $downstream, bool $upstream): array
    {
        $children = [];

        if ($downstream && $wheel->customer_name !== null) {
            $children[] = ['type' => 'customer', 'id' => $wheel->customer_id ?? $wheel->customer_name, 'label' => $wheel->customer_name, 'meta' => ['invoice_ref' => $wheel->invoice_ref, 'sold_weight_kg' => (string) $wheel->sold_weight_kg], 'children' => []];
        }

        if ($upstream) {
            $children[] = $this->batchNode($wheel->batch, false, true);
        }

        return [
            'type' => 'wheel', 'id' => $wheel->id, 'label' => $wheel->serial,
            'meta' => ['status' => $wheel->status, 'grade' => $wheel->grade, 'initial_weight_kg' => (string) $wheel->initial_weight_kg, 'batch_code' => $wheel->batch->code, 'sold_weight_kg' => $wheel->sold_weight_kg === null ? null : (string) $wheel->sold_weight_kg],
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function farmerNode(Breeder $breeder): array
    {
        return ['type' => 'farmer', 'id' => $breeder->id, 'label' => $breeder->name, 'meta' => ['code' => $breeder->code, 'jurisdiction' => $breeder->jurisdiction], 'children' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function testLeaf(QualityTest $test): array
    {
        return [
            'type' => 'test', 'id' => $test->id, 'label' => $test->breeder->name.' · '.$test->sample_date->toDateString(),
            'meta' => ['inhibitors' => $test->inhibitors, 'scc_cells_ml' => $test->scc_cells_ml, 'tbc_cfu_ml' => $test->tbc_cfu_ml], 'children' => [],
        ];
    }

    private function notFound(): never
    {
        throw new DairyCheeseException('The starting point of the trace was not found.', 'trace_not_found', 404);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, true>>  $ids
     */
    private function collect(array $node, array &$ids, float &$wheelKg): void
    {
        $key = match ($node['type']) {
            'farmer' => 'farmer', 'delivery' => 'delivery', 'tank' => 'tank', 'batch' => 'batch', 'wheel' => 'wheel', 'customer' => 'customer', default => null,
        };

        if ($key !== null && ! isset($ids[$key][(string) $node['id']])) {
            $ids[$key][(string) $node['id']] = true;

            if ($key === 'wheel') {
                $wheelKg += (float) ($node['meta']['sold_weight_kg'] ?? $node['meta']['initial_weight_kg'] ?? 0);
            }
        }

        foreach ($node['children'] as $child) {
            $this->collect($child, $ids, $wheelKg);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $wheelIds
     * @param  list<string>  $batchIds
     * @param  list<string>  $deliveryIds
     */
    private function flatten(array $node, array &$wheelIds, array &$batchIds, array &$deliveryIds): void
    {
        $id = (string) $node['id'];

        if ($node['type'] === 'wheel' && ! in_array($id, $wheelIds, true)) {
            $wheelIds[] = $id;
        } elseif ($node['type'] === 'batch' && ! in_array($id, $batchIds, true)) {
            $batchIds[] = $id;
        } elseif ($node['type'] === 'delivery' && ! in_array($id, $deliveryIds, true)) {
            $deliveryIds[] = $id;
        }

        foreach ($node['children'] as $child) {
            $this->flatten($child, $wheelIds, $batchIds, $deliveryIds);
        }
    }
}
