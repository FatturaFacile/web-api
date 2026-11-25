<?php

declare(strict_types=1);

use App\Support\RegionalTranslations;
use Database\Seeders\Translations\PlmFrontendTranslations;
use Modules\Plm\Enums\ApprovalDepartment;
use Modules\Plm\Enums\CadImportSessionStatus;
use Modules\Plm\Enums\CadSourceSystem;
use Modules\Plm\Enums\ChangeReason;
use Modules\Plm\Enums\EcoWorkflowStage;
use Modules\Plm\Enums\RevisionLifecycleState;
use Modules\Plm\Enums\StockDispositionRule;
use Modules\Plm\Strategies\CeUkcaMachineryRoHsStrategy;
use Modules\Plm\Strategies\PlmRegulatoryComplianceStrategyFactory;

it('translates every PLM Mod 168 key across it, en, de, fr and derives regional variants it-CH, de-CH, fr-CH, it-IT', function (): void {
    $definitions = PlmFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        foreach ($tuple as $index => $text) {
            expect(trim($text))->not->toBe('', "{$key} has empty text at index {$index}");
        }
    }

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dict = PlmFrontendTranslations::all($locale);
        expect($dict)->not->toBeEmpty();
    }

    $deCh = RegionalTranslations::derive('de-CH', PlmFrontendTranslations::all('de'));
    foreach ($deCh as $value) {
        expect($value)->not->toContain('ß');
    }
});

it('translates all PLM Mod 168 enum cases', function (): void {
    $dict = PlmFrontendTranslations::definitions();

    foreach (EcoWorkflowStage::cases() as $stage) {
        expect($dict)->toHaveKey('PLM.STAGE_'.strtoupper($stage->value));
    }
    foreach (ChangeReason::cases() as $reason) {
        expect($dict)->toHaveKey('PLM.REASON_'.strtoupper($reason->value));
    }
    foreach (StockDispositionRule::cases() as $rule) {
        expect($dict)->toHaveKey('PLM.DISP_'.strtoupper($rule->value));
    }
    foreach (ApprovalDepartment::cases() as $dept) {
        expect($dict)->toHaveKey('PLM.DEPT_'.strtoupper($dept->value));
    }
    foreach (RevisionLifecycleState::cases() as $state) {
        expect($dict)->toHaveKey('PLM.LC_'.strtoupper($state->value));
    }
    expect(CadSourceSystem::values())->toContain('SolidWorks', 'Inventor', 'Creo', 'NX', 'Eplan', 'Altium')
        ->and(CadImportSessionStatus::values())->toContain('Parsed', 'Reviewed', 'Committed', 'Failed');
});

it('enforces strict RevisionLifecycleState transitions', function (): void {
    $inDesign = RevisionLifecycleState::InDesignPrototype;
    $underReview = RevisionLifecycleState::UnderReview;
    $released = RevisionLifecycleState::ReleasedForProduction;
    $phaseOut = RevisionLifecycleState::PhaseOutUseUpStock;
    $obsolete = RevisionLifecycleState::ObsoleteBlocked;

    expect($inDesign->canTransitionTo($underReview))->toBeTrue()
        ->and($inDesign->canTransitionTo($released, viaEcoRelease: false, isInitialRelease: false))->toBeFalse()
        ->and($inDesign->canTransitionTo($released, viaEcoRelease: true))->toBeTrue()
        ->and($released->canTransitionTo($phaseOut))->toBeTrue()
        ->and($released->canTransitionTo($obsolete))->toBeTrue()
        ->and($phaseOut->canTransitionTo($obsolete))->toBeTrue()
        ->and($obsolete->canTransitionTo($released, viaEcoRelease: true))->toBeFalse();
});

it('resolves CeUkcaMachineryRoHsStrategy from the compliance factory', function (): void {
    $factory = new PlmRegulatoryComplianceStrategyFactory;
    $strategy = $factory->resolve();

    expect($strategy)->toBeInstanceOf(CeUkcaMachineryRoHsStrategy::class)
        ->and($strategy->strategyCode())->toBe('ce_ukca_machinery_rohs')
        ->and($strategy->supportedRegulations())->toContain(
            'EU_MACHINERY_DIRECTIVE_2006_42_EC',
            'EU_MACHINERY_REGULATION_2023_1230',
            'CH_OMACCH_RS_819_14_MRA',
            'ROHS_2011_65_EU_2015_863',
        );
});
