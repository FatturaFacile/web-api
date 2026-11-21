<?php

declare(strict_types=1);

use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionLineConversionStatus;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionApprovalLog;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionLine;
use App\Domain\Procurement\PurchaseRequisition\Services\DelegationThresholdDomainService;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('RequisitionTotalsDomainService computes exact decimals and cost center grouping with line overrides', function (): void {
    $service = new RequisitionTotalsDomainService;

    expect($service->computeLineTotal('2.5000', '19.9900'))->toBe('49.98');

    $summary = $service->summarizeDraftPayload('cc-header', [
        ['quantity' => '2', 'estimated_unit_price' => '150.00', 'cost_center_id' => null],
        ['quantity' => '3', 'estimated_unit_price' => '200.00', 'cost_center_id' => 'cc-override'],
        ['quantity' => '1', 'estimated_unit_price' => '50.25'],
    ]);

    expect($summary['total_estimated_amount'])->toBe('950.25')
        ->and($summary['amounts_by_cost_center'])->toBe([
            'cc-header' => '350.25',
            'cc-override' => '600.00',
        ]);
});

test('RequisitionTotalsDomainService resolves line and header conversion statuses accurately', function (): void {
    $service = new RequisitionTotalsDomainService;

    expect($service->resolveLineConversionStatus('10.0000', '0.0000'))
        ->toBe(RequisitionLineConversionStatus::Unconverted)
        ->and($service->resolveLineConversionStatus('10.0000', '4.0000'))
        ->toBe(RequisitionLineConversionStatus::PartiallyConverted)
        ->and($service->resolveLineConversionStatus('10.0000', '10.0000'))
        ->toBe(RequisitionLineConversionStatus::FullyConverted);

    $lineA = new PurchaseRequisitionLine([
        'quantity' => '10.0000',
        'quantity_requested' => '10.0000',
        'converted_quantity' => '0.0000',
    ]);
    $lineB = new PurchaseRequisitionLine([
        'quantity' => '5.0000',
        'quantity_requested' => '5.0000',
        'converted_quantity' => '0.0000',
    ]);

    expect($service->resolveHeaderConversionStatus([$lineA, $lineB]))->toBe(RequisitionStatus::Approved);

    $lineA->converted_quantity = '4.0000';
    expect($service->resolveHeaderConversionStatus([$lineA, $lineB]))->toBe(RequisitionStatus::PartiallyConverted);

    $lineA->converted_quantity = '10.0000';
    expect($service->resolveHeaderConversionStatus([$lineA, $lineB]))->toBe(RequisitionStatus::PartiallyConverted);

    $lineB->converted_quantity = '5.0000';
    expect($service->resolveHeaderConversionStatus([$lineA, $lineB]))->toBe(RequisitionStatus::Converted);
});

test('DelegationThresholdDomainService routes multi-level cumulative tiers for CHF and EUR thresholds', function (): void {
    $service = new DelegationThresholdDomainService;

    // Tier 1 only (< 1,000)
    $tier1Chf = $service->resolveMatchingTiers('999.99', 'CHF');
    expect($tier1Chf)->toHaveCount(1)
        ->and($tier1Chf[0]->level)->toBe(1)
        ->and($tier1Chf[0]->approverValue)->toBe('department_manager')
        ->and($tier1Chf[0]->currency)->toBe('CHF');

    // Tier 1 + 2 (1,000 - 10,000)
    $tier2Eur = $service->resolveMatchingTiers('1000.00', 'EUR');
    expect($tier2Eur)->toHaveCount(2)
        ->and(array_column($tier2Eur, 'approverValue'))->toBe(['department_manager', 'division_director']);

    $tier2Boundary = $service->resolveMatchingTiers('10000.00', 'CHF');
    expect($tier2Boundary)->toHaveCount(2);

    // Tier 1 + 2 + 3 (> 10,000)
    $tier3Eur = $service->resolveMatchingTiers('10000.01', 'EUR');
    expect($tier3Eur)->toHaveCount(3)
        ->and(array_column($tier3Eur, 'approverValue'))->toBe(['department_manager', 'division_director', 'cfo'])
        ->and($tier3Eur[2]->currency)->toBe('EUR');
});

test('PurchaseRequisitionApprovalLog enforces strict immutability on update and delete', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $requisition = PurchaseRequisition::query()->create([
        'tenant_id' => $tenant->id,
        'requisition_number' => 'PR-2026-0001',
        'title' => 'Immutable Log Test',
        'requester_user_id' => $user->id,
        'requestor_user_id' => $user->id,
        'urgency' => 'normal',
        'currency' => 'CHF',
        'total_estimated_amount' => '100.00',
        'status' => 'draft',
    ]);

    $log = PurchaseRequisitionApprovalLog::query()->create([
        'tenant_id' => $tenant->id,
        'purchase_requisition_id' => $requisition->id,
        'approval_step_level' => 0,
        'approver_user_id' => $user->id,
        'action' => 'submitted',
        'channel' => 'web',
        'acted_at' => now(),
    ]);

    expect(fn () => $log->update(['comment' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});
