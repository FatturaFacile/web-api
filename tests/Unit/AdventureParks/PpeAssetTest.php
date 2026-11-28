<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\AdventureParks\Domain\Enums\InspectionOutcome;
use Modules\AdventureParks\Domain\Enums\InspectionType;
use Modules\AdventureParks\Domain\Enums\PpeStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\PpeAsset;

/**
 * @param  array<string, mixed>  $overrides
 */
function advHarness(array $overrides = []): PpeAsset
{
    return new PpeAsset(array_merge([
        'serial_code' => 'AD-000001',
        'category' => 'HARNESS',
        'size' => 'ADULT',
        'status' => 'AVAILABLE',
        'purchase_date' => '2026-01-10',
        'manufacturer_max_lifespan_date' => '2026-12-31',
        'next_inspection_due_date' => '2026-09-30',
        'rental_cycle_count' => 0,
        'cumulative_usage_hours' => '0.00',
    ], $overrides));
}

describe('blocking rules (EN 365)', function (): void {
    it('can be assigned while it is available, within its life and its inspection', function (): void {
        $asset = advHarness();

        expect($asset->blockedReason(CarbonImmutable::parse('2026-06-15')))->toBeNull()
            ->and($asset->isAssignableOn(CarbonImmutable::parse('2026-06-15')))->toBeTrue()
            // the last day of the life and of the inspection are still good
            ->and($asset->isAssignableOn(CarbonImmutable::parse('2026-09-30 18:00:00')))->toBeTrue();
    });

    it('is blocked the day after the manufacturer lifespan', function (): void {
        $asset = advHarness(['next_inspection_due_date' => null]);

        expect($asset->isAssignableOn(CarbonImmutable::parse('2026-12-31 23:59:00')))->toBeTrue()
            ->and($asset->blockedReason(CarbonImmutable::parse('2027-01-01')))->toBe('LIFESPAN_EXPIRED');
    });

    it('is blocked the day after the annual inspection was due', function (): void {
        $asset = advHarness();

        expect($asset->blockedReason(CarbonImmutable::parse('2026-10-01')))->toBe('INSPECTION_OVERDUE');
    });

    it('reports the end of the life before the overdue inspection and any status other than AVAILABLE last', function (): void {
        $asset = advHarness(['manufacturer_max_lifespan_date' => '2026-06-01', 'next_inspection_due_date' => '2026-05-01']);
        $inUse = advHarness(['status' => 'IN_USE']);
        $maintenance = advHarness(['status' => 'MAINTENANCE_REQUIRED']);

        expect($asset->blockedReason(CarbonImmutable::parse('2026-06-15')))->toBe('LIFESPAN_EXPIRED')
            ->and($inUse->blockedReason(CarbonImmutable::parse('2026-06-15')))->toBe('STATUS')
            ->and($maintenance->blockedReason(CarbonImmutable::parse('2026-06-15')))->toBe('STATUS');
    });

    it('counts in the stock of a day while it is in the park and valid that day', function (): void {
        $day = CarbonImmutable::parse('2026-06-15');

        expect(advHarness()->countsAsStockOn($day))->toBeTrue()
            ->and(advHarness(['status' => 'IN_USE'])->countsAsStockOn($day))->toBeTrue()
            ->and(advHarness(['status' => 'MAINTENANCE_REQUIRED'])->countsAsStockOn($day))->toBeFalse()
            ->and(advHarness(['status' => 'EXPIRED_BLOCKED'])->countsAsStockOn($day))->toBeFalse()
            ->and(advHarness(['status' => 'DECOMMISSIONED'])->countsAsStockOn($day))->toBeFalse()
            ->and(advHarness()->countsAsStockOn(CarbonImmutable::parse('2026-10-15')))->toBeFalse();
    });
});

describe('status synchronisation', function (): void {
    it('moves an available item to EXPIRED_BLOCKED when its life or its inspection ran out', function (): void {
        $overdue = advHarness();
        $expired = advHarness(['next_inspection_due_date' => null]);
        $fine = advHarness();

        expect($overdue->syncStatus(CarbonImmutable::parse('2026-10-15')))->toBeTrue()
            ->and($overdue->status)->toBe(PpeStatus::ExpiredBlocked->value)
            ->and($expired->syncStatus(CarbonImmutable::parse('2027-02-01')))->toBeTrue()
            ->and($expired->status)->toBe('EXPIRED_BLOCKED')
            ->and($fine->syncStatus(CarbonImmutable::parse('2026-06-15')))->toBeFalse()
            ->and($fine->status)->toBe('AVAILABLE');
    });

    it('leaves the items in maintenance or decommissioned as they are', function (): void {
        $maintenance = advHarness(['status' => 'MAINTENANCE_REQUIRED']);
        $decommissioned = advHarness(['status' => 'DECOMMISSIONED']);

        expect($maintenance->syncStatus(CarbonImmutable::parse('2030-01-01')))->toBeFalse()
            ->and($maintenance->status)->toBe('MAINTENANCE_REQUIRED')
            ->and($decommissioned->syncStatus(CarbonImmutable::parse('2030-01-01')))->toBeFalse();
    });
});

describe('hand-out and return', function (): void {
    it('puts the item in use and records the first use', function (): void {
        $asset = advHarness();
        $asset->assign(CarbonImmutable::parse('2026-06-15 10:30:00'));

        expect($asset->status)->toBe('IN_USE')->and($asset->first_use_date->toDateString())->toBe('2026-06-15');
    });

    it('refuses to hand out a blocked item and says why', function (): void {
        $asset = advHarness(['manufacturer_max_lifespan_date' => '2026-06-01']);

        try {
            $asset->assign(CarbonImmutable::parse('2026-06-15'));
            $this->fail('A harness past its life must not be assigned.');
        } catch (AdventureParksException $exception) {
            expect($exception->errorCode())->toBe('ppe_blocked')
                ->and($exception->context())->toMatchArray(['serial_code' => 'AD-000001', 'category' => 'HARNESS', 'reason' => 'LIFESPAN_EXPIRED']);
        }
    });

    it('counts the rental cycle and the hours when the item comes back', function (): void {
        $asset = advHarness(['status' => 'IN_USE']);
        $asset->release(1.5, CarbonImmutable::parse('2026-06-15'));
        $asset->status = 'IN_USE';
        $asset->release(2.0, CarbonImmutable::parse('2026-06-16'));

        expect($asset->rental_cycle_count)->toBe(2)->and($asset->cumulative_usage_hours)->toBe('3.50')->and($asset->status)->toBe('AVAILABLE');
    });

    it('blocks the item that comes back after the end of its life', function (): void {
        $asset = advHarness(['status' => 'IN_USE', 'manufacturer_max_lifespan_date' => '2026-06-15', 'next_inspection_due_date' => null]);
        $asset->release(1.0, CarbonImmutable::parse('2026-06-16'));

        expect($asset->status)->toBe('EXPIRED_BLOCKED')->and($asset->rental_cycle_count)->toBe(1);
    });
});

describe('inspections', function (): void {
    it('sends the item to maintenance after a failed inspection of any kind', function (InspectionType $type): void {
        $asset = advHarness();
        $asset->applyInspection($type, InspectionOutcome::Fail, CarbonImmutable::parse('2026-06-15'));

        expect($asset->status)->toBe('MAINTENANCE_REQUIRED')->and($asset->last_inspection_date->toDateString())->toBe('2026-06-15');
    })->with([InspectionType::DailyVisual, InspectionType::AnnualEn365, InspectionType::Incident]);

    it('unlocks the item and moves the next due date a year ahead after a passed annual inspection', function (): void {
        $asset = advHarness(['status' => 'EXPIRED_BLOCKED', 'next_inspection_due_date' => '2026-05-01']);
        $asset->applyInspection(InspectionType::AnnualEn365, InspectionOutcome::Pass, CarbonImmutable::parse('2026-06-15'));

        expect($asset->status)->toBe('AVAILABLE')
            ->and($asset->next_inspection_due_date->toDateString())->toBe('2027-06-15')
            ->and($asset->last_inspection_date->toDateString())->toBe('2026-06-15');
    });

    it('takes the next due date the inspector states', function (): void {
        $asset = advHarness(['status' => 'MAINTENANCE_REQUIRED']);
        $asset->applyInspection(InspectionType::AnnualEn365, InspectionOutcome::Pass, CarbonImmutable::parse('2026-06-15'), CarbonImmutable::parse('2026-12-01'));

        expect($asset->status)->toBe('AVAILABLE')->and($asset->next_inspection_due_date->toDateString())->toBe('2026-12-01');
    });

    it('does not unlock the item with a passed visual check and never revives one past its life or decommissioned', function (): void {
        $maintenance = advHarness(['status' => 'MAINTENANCE_REQUIRED']);
        $maintenance->applyInspection(InspectionType::DailyVisual, InspectionOutcome::Pass, CarbonImmutable::parse('2026-06-15'));

        $old = advHarness(['status' => 'EXPIRED_BLOCKED', 'manufacturer_max_lifespan_date' => '2026-06-01']);
        $old->applyInspection(InspectionType::AnnualEn365, InspectionOutcome::Pass, CarbonImmutable::parse('2026-06-15'));

        $scrapped = advHarness(['status' => 'DECOMMISSIONED']);
        $scrapped->applyInspection(InspectionType::AnnualEn365, InspectionOutcome::Fail, CarbonImmutable::parse('2026-06-15'));

        expect($maintenance->status)->toBe('MAINTENANCE_REQUIRED')
            ->and($old->status)->toBe('EXPIRED_BLOCKED')
            ->and($scrapped->status)->toBe('DECOMMISSIONED');
    });
});

describe('life of the item', function (): void {
    it('reports the days left and the share of the life used', function (): void {
        $asset = advHarness(['purchase_date' => '2026-01-01', 'first_use_date' => '2026-01-01', 'manufacturer_max_lifespan_date' => '2026-12-31']);
        $today = CarbonImmutable::parse('2026-07-01');

        expect($asset->lifespanRemainingDays($today))->toBe(183)
            ->and($asset->lifespanPercentUsed($today))->toBe(round(181 / 364 * 100, 1))
            ->and($asset->lifespanRemainingDays(CarbonImmutable::parse('2027-01-10')))->toBe(-10)
            ->and($asset->lifespanPercentUsed(CarbonImmutable::parse('2027-06-01')))->toBe(100.0)
            ->and(advHarness(['purchase_date' => null, 'first_use_date' => null])->lifespanPercentUsed($today))->toBe(0.0);
    });
});
