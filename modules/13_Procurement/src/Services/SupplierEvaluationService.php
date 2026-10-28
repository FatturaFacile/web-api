<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Contracts\SupplierEvaluationServiceInterface;
use Modules\Procurement\DTOs\EvaluateSupplierDTO;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierEvaluation;
use Modules\Procurement\Models\SupplierScorecard;

final class SupplierEvaluationService implements SupplierEvaluationServiceInterface
{
    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger
    ) {}

    public function recordEvaluation(EvaluateSupplierDTO $dto): SupplierEvaluation
    {
        return DB::transaction(function () use ($dto): SupplierEvaluation {
            $supplier = Supplier::where('id', $dto->supplierId)->firstOrFail();

            $evaluation = SupplierEvaluation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $supplier->tenant_id,
                'company_id' => $supplier->company_id,
                'supplier_id' => $supplier->id,
                'evaluation_period_start' => $dto->periodStart,
                'evaluation_period_end' => $dto->periodEnd,
                'on_time_delivery_rate' => $dto->onTimeDeliveryRate,
                'quality_acceptance_rate' => $dto->qualityAcceptanceRate,
                'price_competitiveness_score' => $dto->priceCompetitivenessScore,
                'overall_rating' => $dto->overallRating,
                'is_approved_vendor' => $dto->isApprovedVendor,
                'evaluator_user_id' => $dto->evaluatorUserId,
                'comments' => $dto->comments,
            ]);

            // Update master supplier AVL and rating
            $supplier->update([
                'rating' => $dto->overallRating,
                'is_approved_vendor' => $dto->isApprovedVendor,
            ]);

            $this->auditLedger->record(
                action: 'SUPPLIER_EVALUATED',
                model: $evaluation,
                payloadBefore: null,
                payloadAfter: [
                    'supplier_id' => $supplier->id,
                    'rating' => $dto->overallRating,
                    'is_approved_vendor' => $dto->isApprovedVendor,
                ]
            );

            return $evaluation;
        });
    }

    public function generateQuarterlyScorecard(Supplier $supplier, int $year, int $quarter): SupplierScorecard
    {
        return DB::transaction(function () use ($supplier, $year, $quarter): SupplierScorecard {
            $start = Carbon::create($year)->quarter($quarter)->startOfQuarter();
            $end = Carbon::create($year)->quarter($quarter)->endOfQuarter();

            $orders = PurchaseOrder::where('supplier_id', $supplier->id)
                ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
                ->with('receipts')
                ->get();

            $totalOrders = $orders->count();
            $onTimeOrders = 0;

            foreach ($orders as $order) {
                $firstReceipt = $order->receipts->sortBy('received_date')->first();
                if ($firstReceipt !== null && $order->expected_delivery_date !== null) {
                    if (Carbon::parse($firstReceipt->received_date)->lte(Carbon::parse($order->expected_delivery_date))) {
                        $onTimeOrders++;
                    }
                } elseif ($firstReceipt !== null) {
                    $onTimeOrders++;
                }
            }

            $otifRate = $totalOrders > 0 ? ($onTimeOrders / $totalOrders) * 100.0 : 100.0;
            $riskLevel = $otifRate < 80.0 ? 'high' : ($otifRate < 95.0 ? 'medium' : 'low');

            $scorecard = SupplierScorecard::updateOrCreate(
                [
                    'tenant_id' => $supplier->tenant_id,
                    'supplier_id' => $supplier->id,
                    'year' => $year,
                    'quarter' => $quarter,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $supplier->company_id,
                    'otif_percentage' => round($otifRate, 2),
                    'defect_ppm' => 0,
                    'cost_savings_amount' => 0.00,
                    'compliance_score' => 100.00,
                    'risk_level' => $riskLevel,
                    'is_avl_active' => $supplier->is_approved_vendor,
                    'scorecard_data' => [
                        'total_orders' => $totalOrders,
                        'on_time_orders' => $onTimeOrders,
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                    ],
                ]
            );

            return $scorecard;
        });
    }
}
