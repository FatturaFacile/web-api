<?php

declare(strict_types=1);

use App\Services\Crm\Fiscal\FiscalIdentifiers;
use Modules\Customs\Enums\CustomsWorkflowStage;
use Modules\Customs\Enums\DeclarationStatus;

it('maps declaration status and assessment decision to the workflow stage', function (DeclarationStatus $status, bool $decision, ?string $stage): void {
    expect(CustomsWorkflowStage::resolve($status, $decision)?->value)->toBe($stage);
})->with([
    [DeclarationStatus::Draft, false, 'draft'],
    [DeclarationStatus::Validated, false, 'draft'],
    [DeclarationStatus::Rejected, false, 'draft'],
    [DeclarationStatus::Submitted, false, 'declared'],
    [DeclarationStatus::Accepted, false, 'cleared'],
    [DeclarationStatus::Accepted, true, 'archived_evv'],
    [DeclarationStatus::Cancelled, false, null],
]);

it('only allows the stages to advance one step at a time', function (): void {
    expect(CustomsWorkflowStage::Draft->next())->toBe([CustomsWorkflowStage::Declared])
        ->and(CustomsWorkflowStage::ArchivedEvv->next())->toBe([]);
});

it('validates EORI numbers by country', function (string $eori, bool $valid): void {
    expect(FiscalIdentifiers::isValidEori($eori))->toBe($valid);
})->with([
    ['IT12345678901', true],
    ['IT1234', false],
    ['DE123456789012345', true],
    ['DE', false],
    ['X1', false],
    ['CHE123456788', true],
    ['CHE123456789', false],
    ['CHE-123.456.788', true],
]);
