<?php

declare(strict_types=1);

use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Domain\Enums\KgBasis;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;

it('follows the garment lifecycle from the clean stock to the rag', function (): void {
    expect(LifecycleState::CleanInStock->canMoveTo(LifecycleState::DeliveredToCustomer))->toBeTrue()
        ->and(LifecycleState::DeliveredToCustomer->canMoveTo(LifecycleState::SoiledReturned))->toBeTrue()
        ->and(LifecycleState::SoiledReturned->canMoveTo(LifecycleState::InWashTunnel))->toBeTrue()
        ->and(LifecycleState::InWashTunnel->canMoveTo(LifecycleState::DeliveredToCustomer))->toBeTrue()
        ->and(LifecycleState::InWashTunnel->canMoveTo(LifecycleState::InMending))->toBeTrue()
        ->and(LifecycleState::InMending->canMoveTo(LifecycleState::CleanInStock))->toBeTrue()
        ->and(LifecycleState::InMending->canMoveTo(LifecycleState::DeliveredToCustomer))->toBeFalse();
});

it('never leaves the condemned rag state', function (): void {
    expect(LifecycleState::CondemnedRag->next())->toBe([])
        ->and(LifecycleState::CondemnedRag->isCirculating())->toBeFalse()
        ->and(LifecycleState::DeliveredToCustomer->isCirculating())->toBeTrue();

    foreach (LifecycleState::cases() as $state) {
        expect($state->canMoveTo(LifecycleState::CondemnedRag))->toBe($state !== LifecycleState::CondemnedRag);
    }
});

it('counts a wash only when the garment went through the soiled or tunnel state', function (): void {
    expect(LifecycleState::SoiledReturned->completesWash())->toBeTrue()
        ->and(LifecycleState::InWashTunnel->completesWash())->toBeTrue()
        ->and(LifecycleState::CleanInStock->completesWash())->toBeFalse()
        ->and(LifecycleState::DeliveredToCustomer->completesWash())->toBeFalse();
});

it('tells which article types are worn by one employee and which are PPE', function (): void {
    expect(ArticleType::PersonalWorkwearUniform->isNominal())->toBeTrue()
        ->and(ArticleType::PpeSafetyGarment->isNominal())->toBeTrue()
        ->and(ArticleType::FlatLinen->isNominal())->toBeFalse()
        ->and(ArticleType::PpeSafetyGarment->isPpe())->toBeTrue()
        ->and(ArticleType::PersonalWorkwearUniform->isPpe())->toBeFalse();
});

it('maps the billing models to what they bill', function (): void {
    expect(BillingModel::PerPieceWashed->billsWashPerPiece())->toBeTrue()
        ->and(BillingModel::PerPieceWashed->billsRental())->toBeFalse()
        ->and(BillingModel::PerKgDirtyOrClean->billsWashPerKg())->toBeTrue()
        ->and(BillingModel::FixedMonthlyRentalPerGarment->billsRental())->toBeTrue()
        ->and(BillingModel::FixedMonthlyRentalPerGarment->billsWashPerPiece())->toBeFalse()
        ->and(BillingModel::HybridRentalPlusWash->billsRental())->toBeTrue()
        ->and(BillingModel::HybridRentalPlusWash->billsWashPerPiece())->toBeTrue();
});

it('maps the weighing bases to the gate directions and the risk zones to the disinfection rule', function (): void {
    expect(KgBasis::Dirty->direction())->toBe(ScanDirection::InboundSoil)
        ->and(KgBasis::Clean->direction())->toBe(ScanDirection::OutboundClean)
        ->and(RabcRiskZone::Standard->requiresDisinfection())->toBeFalse()
        ->and(RabcRiskZone::Contaminated->requiresDisinfection())->toBeTrue()
        ->and(RabcRiskZone::Protected->requiresDisinfection())->toBeTrue();
});
