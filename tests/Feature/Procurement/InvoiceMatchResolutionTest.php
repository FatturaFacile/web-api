<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Procurement\Enums\InvoiceMatchLineStatus;
use Modules\Procurement\Enums\InvoiceMatchStatus;
use Modules\Procurement\Enums\MatchResolutionAction;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\InvoiceMatchResolution;
use Modules\Procurement\Services\InvoiceMatchResolutionService;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    matchingSetUp($this);
    $this->resolutions = app(InvoiceMatchResolutionService::class);
});

it('asks the supplier for a credit note and keeps the invoice blocked', function (): void {
    $run = matchingBlockedRun($this);

    $updated = Fixtures::in($this->tenant, fn () => $this->resolutions->requestCreditNote($run, (string) $this->owner->id, 'Price above the contract', '100.00'));

    $resolution = InvoiceMatchResolution::query()->where('run_id', $run->id)->firstOrFail();

    expect($updated->status)->toBe(InvoiceMatchStatus::PendingCreditNote)
        ->and($updated->status->isBlocking())->toBeTrue()
        ->and($run->invoice->fresh()->match_status)->toBe('pending_credit_note')
        ->and($run->invoice->fresh()->isPaymentBlocked())->toBeTrue()
        ->and($resolution->action)->toBe(MatchResolutionAction::RequestCreditNote)
        ->and($resolution->from_status)->toBe('blocked_discrepancy')
        ->and($resolution->to_status)->toBe('pending_credit_note')
        ->and($resolution->actor_id)->toBe((string) $this->owner->id)
        ->and($resolution->context['expected_credit_amount'])->toBe('100.00');
});

it('expects the whole variance as credit when no amount is named', function (): void {
    $run = matchingBlockedRun($this);

    Fixtures::in($this->tenant, fn () => $this->resolutions->requestCreditNote($run, (string) $this->owner->id, 'Wrong price'));

    expect(InvoiceMatchResolution::query()->where('run_id', $run->id)->firstOrFail()->context['expected_credit_amount'])->toBe($run->variance_amount);
});

it('releases the invoice when a re-match finds the goods receipt that was booked late', function (): void {
    $order = Fixtures::order($this->tenant, $this->supplier, $this->poLines);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);
    $blocked = matchingRun($this, $invoice, (string) $order->id);
    expect($blocked->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy);

    Fixtures::receive($this->tenant, $order, [1 => 10, 2 => 5], (string) $this->owner->id);
    $fresh = Fixtures::in($this->tenant, fn () => $this->resolutions->rematch($blocked, (string) $this->owner->id, 'Receipt booked late'));

    expect($fresh->id)->not->toBe($blocked->id)
        ->and($fresh->is_current)->toBeTrue()
        ->and($fresh->status)->toBe(InvoiceMatchStatus::Matched)
        ->and($blocked->fresh()->is_current)->toBeFalse()
        ->and($invoice->fresh()->isPaymentBlocked())->toBeFalse()
        ->and($fresh->resolutions->first()->action)->toBe(MatchResolutionAction::Rematch)
        ->and($fresh->resolutions->first()->context['previous_run_id'])->toBe((string) $blocked->id);
});

it('keeps the invoice blocked when a re-match still finds the discrepancy', function (): void {
    $run = matchingBlockedRun($this);

    $fresh = Fixtures::in($this->tenant, fn () => $this->resolutions->rematch($run, (string) $this->owner->id));

    expect($fresh->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)->and($fresh->invoice->isPaymentBlocked())->toBeTrue();
});

it('accepts discrepant lines one at a time and releases the invoice when none is left', function (): void {
    $order = matchingReceivedOrder($this);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '25.00']]);
    $run = matchingRun($this, $invoice, (string) $order->id);
    $lines = $run->lines->keyBy('line_number');
    expect($run->discrepancy_count)->toBe(2);

    $partial = Fixtures::in($this->tenant, fn () => $this->resolutions->resolvePartially($run, (string) $this->owner->id, 'Fan price agreed by phone', [(string) $lines[1]->id]));

    expect($partial->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy)
        ->and($partial->discrepancy_count)->toBe(1)
        ->and($partial->lines->firstWhere('line_number', 1)->status)->toBe(InvoiceMatchLineStatus::Accepted)
        ->and($partial->lines->firstWhere('line_number', 2)->status)->toBe(InvoiceMatchLineStatus::PriceVariance)
        ->and($invoice->fresh()->isPaymentBlocked())->toBeTrue();

    $done = Fixtures::in($this->tenant, fn () => $this->resolutions->resolvePartially($partial, (string) $this->owner->id, 'Kit price agreed too', [(string) $lines[2]->id]));

    expect($done->status)->toBe(InvoiceMatchStatus::Resolved)
        ->and($done->discrepancy_count)->toBe(0)
        ->and($done->released_at)->not->toBeNull()
        ->and($done->gr_ir_amount)->toBe('1100.00')
        ->and($invoice->fresh()->match_status)->toBe('resolved')
        ->and($invoice->fresh()->isPaymentBlocked())->toBeFalse()
        ->and($done->resolutions)->toHaveCount(2);
});

it('refuses a partial resolution of lines that are not discrepancies of the run', function (): void {
    $run = matchingBlockedRun($this);
    $matchedLine = $run->lines->firstWhere('line_number', 2);

    expect(fn () => Fixtures::in($this->tenant, fn () => $this->resolutions->resolvePartially($run, (string) $this->owner->id, 'x', [(string) $matchedLine->id])))
        ->toThrow(ProcurementRuleViolation::class);
    expect(fn () => Fixtures::in($this->tenant, fn () => $this->resolutions->resolvePartially($run, (string) $this->owner->id, 'x', [])))
        ->toThrow(ProcurementRuleViolation::class);
});

it('lets a supervisor override the block, accepting every discrepancy with the reason in the audit history', function (): void {
    $run = matchingBlockedRun($this);

    $overridden = Fixtures::in($this->tenant, fn () => $this->resolutions->override($run, (string) $this->owner->id, 'Price increase approved by the CFO'));
    $resolution = InvoiceMatchResolution::query()->where('run_id', $run->id)->firstOrFail();

    expect($overridden->status)->toBe(InvoiceMatchStatus::OverrideApproved)
        ->and($overridden->released_at)->not->toBeNull()
        ->and($overridden->lines->every(fn ($line): bool => ! $line->status->isBlocking()))->toBeTrue()
        ->and($overridden->lines->firstWhere('line_number', 1)->status)->toBe(InvoiceMatchLineStatus::Accepted)
        ->and($run->invoice->fresh()->match_status)->toBe('override_approved')
        ->and($run->invoice->fresh()->isPaymentBlocked())->toBeFalse()
        ->and($resolution->action)->toBe(MatchResolutionAction::Override)
        ->and($resolution->reason)->toBe('Price increase approved by the CFO')
        ->and($resolution->actor_id)->toBe((string) $this->owner->id)
        ->and($resolution->context['accepted_lines'])->toBe(1);
});

it('clears the accrual of the goods received when it overrides, and leaves the surplus as a cost', function (): void {
    $run = matchingBlockedRun($this);

    $overridden = Fixtures::in($this->tenant, fn () => $this->resolutions->override($run, (string) $this->owner->id, 'Agreed with the supplier'));

    // Invoiced 1,200 (10 x 110 + 5 x 20): the accrual of the goods received (1,100) is cleared, 100 is price variance.
    expect($overridden->gr_ir_amount)->toBe('1100.00')
        ->and($overridden->price_variance_amount)->toBe('100.00');
});

it('requires a reason for the override', function (): void {
    $run = matchingBlockedRun($this);

    expect(fn () => Fixtures::in($this->tenant, fn () => $this->resolutions->override($run, (string) $this->owner->id, '   ')))
        ->toThrow(ProcurementRuleViolation::class);
    expect($run->fresh()->status)->toBe(InvoiceMatchStatus::BlockedDiscrepancy);
});

it('refuses to resolve an invoice that is not blocked or whose run is no longer current', function (): void {
    $order = matchingReceivedOrder($this);
    $matched = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, $this->poLines), (string) $order->id);

    expect(fn () => Fixtures::in($this->tenant, fn () => $this->resolutions->override($matched, (string) $this->owner->id, 'Not needed')))
        ->toThrow(ProcurementRuleViolation::class);

    $blocked = matchingBlockedRun($this);
    Fixtures::in($this->tenant, fn () => $this->resolutions->rematch($blocked, (string) $this->owner->id));

    expect(fn () => Fixtures::in($this->tenant, fn () => $this->resolutions->override($blocked->fresh(), (string) $this->owner->id, 'Stale run')))
        ->toThrow(ProcurementRuleViolation::class);
});

it('lets a pending credit note be resolved by an override', function (): void {
    $run = matchingBlockedRun($this);
    $pending = Fixtures::in($this->tenant, fn () => $this->resolutions->requestCreditNote($run, (string) $this->owner->id, 'Credit note requested'));

    $overridden = Fixtures::in($this->tenant, fn () => $this->resolutions->override($pending, (string) $this->owner->id, 'Supplier will not credit'));

    expect($overridden->status)->toBe(InvoiceMatchStatus::OverrideApproved)
        ->and($overridden->resolutions()->count())->toBe(2);
});
