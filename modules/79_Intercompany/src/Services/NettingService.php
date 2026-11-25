<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\PassiveInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\ConsolidationException;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationTranslator;
use Modules\Intercompany\Enums\DocumentKind;
use Modules\Intercompany\Enums\LinkStatus;
use Modules\Intercompany\Enums\NettingStatus;
use Modules\Intercompany\Enums\PaymentState;
use Modules\Intercompany\Enums\SyncEventType;
use Modules\Intercompany\Exceptions\IntercompanyConflictException;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Models\NettingItem;
use Modules\Intercompany\Models\NettingRun;
use Modules\Intercompany\Models\TransactionLink;
use Modules\Intercompany\Support\GroupScope;

/**
 * Netting of the open intercompany items of two entities (compensazione delle partite). The open documents A issued to B
 * and those B issued to A, in the netting currency (foreign items at the rate of the date through the group currency),
 * offset each other up to the smaller side; the rest is what one entity still pays the other. The proposal is a draft
 * that changes nothing; confirming it settles the offset amount on the oldest documents first, on both sides, and logs
 * it. A difference below the amount tolerance is a rounding variance and closes as `balanced`. Credit notes count as
 * negative items.
 */
final readonly class NettingService
{
    public function __construct(
        private GroupScope $scope,
        private SettingsService $settings,
        private ConsolidationTranslator $translator,
        private SyncLog $log,
    ) {}

    public function propose(User $user, ConsolidationGroup $group, string $entityA, string $entityB, CarbonImmutable $asOf, ?string $currency = null): NettingRun
    {
        $this->scope->assertReadable($user, $group);
        $this->scope->assertEntities($group, [$entityA, $entityB]);

        if ($entityA === $entityB) {
            throw new IntercompanyException('Netting needs two different entities.', 'same_entity');
        }

        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $entityA, $entityB, $asOf, $currency): NettingRun {
            $settings = $this->settings->forGroup($group);

            if (! $settings->netting_enabled) {
                throw new IntercompanyException('Netting is not enabled for this group.', 'netting_disabled');
            }

            $sideA = $this->openLinks($group, $entityA, $entityB, $asOf);
            $sideB = $this->openLinks($group, $entityB, $entityA, $asOf);
            $first = $sideA->first() ?? $sideB->first();
            $currency = strtoupper($currency ?? ($first !== null ? (string) $first->currency : (string) $group->group_currency));

            return DB::transaction(function () use ($group, $settings, $entityA, $entityB, $asOf, $currency, $sideA, $sideB): NettingRun {
                $run = NettingRun::withoutGlobalScopes()->create([
                    'tenant_id' => $group->tenant_id,
                    'group_id' => $group->id,
                    'entity_a_tenant_id' => $entityA,
                    'entity_b_tenant_id' => $entityB,
                    'as_of_date' => $asOf->toDateString(),
                    'currency' => $currency,
                    'status' => NettingStatus::Draft->value,
                ]);

                $totals = ['a' => ConsolidationMath::zero(), 'b' => ConsolidationMath::zero()];
                $fxVariance = ConsolidationMath::zero();

                foreach (['a' => $sideA, 'b' => $sideB] as $side => $links) {
                    foreach ($links as $link) {
                        $open = $this->openAmount($link);
                        $rate = $this->rate((string) $link->currency, $currency, (string) $group->group_currency, $asOf);
                        $applied = ConsolidationMath::mul($open, $rate);

                        if ((string) $link->currency !== $currency) {
                            $fxVariance = ConsolidationMath::add($fxVariance, ConsolidationMath::sub($applied, $open));
                        }

                        NettingItem::withoutGlobalScopes()->create([
                            'tenant_id' => $group->tenant_id,
                            'netting_run_id' => $run->id,
                            'transaction_id' => $link->id,
                            'side' => $side,
                            'document_number' => $link->source_document_number,
                            'currency' => $link->currency,
                            'open_amount' => $open,
                            'applied_amount' => ConsolidationMath::zero(),
                            'fx_rate' => $rate,
                            'variance' => ConsolidationMath::sub($applied, $open),
                        ]);
                        $totals[$side] = ConsolidationMath::add($totals[$side], $applied);
                    }
                }

                $net = ConsolidationMath::sub($totals['a'], $totals['b']);
                $absolute = ConsolidationMath::abs($net);
                $rounding = ConsolidationMath::cmp($absolute, (string) $settings->amount_tolerance) <= 0;

                $run->forceFill([
                    'receivable_total' => $totals['a'],
                    'payable_total' => $totals['b'],
                    'net_amount' => $rounding ? ConsolidationMath::zero() : $absolute,
                    'net_direction' => match (true) {
                        $rounding => 'balanced',
                        ConsolidationMath::isPositive($net) => 'b_pays_a',
                        default => 'a_pays_b',
                    },
                    'fx_variance' => $fxVariance,
                    'amount_variance' => $rounding ? $absolute : ConsolidationMath::zero(),
                ])->save();

                return $run->refresh();
            });
        });
    }

    public function confirm(User $user, ConsolidationGroup $group, NettingRun $run, ?int $expectedVersion = null): NettingRun
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($user, $group, $run, $expectedVersion): NettingRun {
            $this->assertDraft($run, $expectedVersion);
            $offset = ConsolidationMath::min((string) $run->receivable_total, (string) $run->payable_total);

            return DB::transaction(function () use ($user, $group, $run, $offset): NettingRun {
                foreach (['a', 'b'] as $side) {
                    $remaining = $offset;
                    $items = NettingItem::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->where('netting_run_id', $run->id)->where('side', $side)->orderBy('created_at')->get();

                    foreach ($items as $item) {
                        $capacity = ConsolidationMath::mul((string) $item->open_amount, (string) $item->fx_rate);

                        if (! ConsolidationMath::isPositive($capacity) || ! ConsolidationMath::isPositive($remaining)) {
                            continue;
                        }

                        $applied = ConsolidationMath::min($capacity, $remaining);
                        $remaining = ConsolidationMath::sub($remaining, $applied);
                        $item->forceFill(['applied_amount' => $applied])->save();
                        $this->settle($group, $item, $applied, $user);
                    }
                }

                $run->forceFill(['status' => NettingStatus::Confirmed->value, 'confirmed_at' => now(), 'version' => $run->version + 1])->save();

                return $run->refresh();
            });
        });
    }

    public function cancel(User $user, ConsolidationGroup $group, NettingRun $run, string $reason, ?int $expectedVersion = null): NettingRun
    {
        $this->scope->assertReadable($user, $group);

        return $this->scope->runAs((string) $group->tenant_id, function () use ($run, $reason, $expectedVersion): NettingRun {
            $this->assertDraft($run, $expectedVersion);
            $run->forceFill(['status' => NettingStatus::Cancelled->value, 'cancel_reason' => $reason, 'version' => $run->version + 1])->save();

            return $run->refresh();
        });
    }

    /**
     * Settles an amount of the netting currency on the document of an item: the paid amount of the link and of the mirror
     * grow by the native amount.
     */
    private function settle(ConsolidationGroup $group, NettingItem $item, string $applied, User $user): void
    {
        $link = TransactionLink::withoutGlobalScopes()->where('tenant_id', $group->tenant_id)->find($item->transaction_id);

        if ($link === null) {
            return;
        }

        $native = ConsolidationMath::isZero((string) $item->fx_rate) ? $applied : bcdiv($applied, (string) $item->fx_rate, 4);
        $paid = ConsolidationMath::min(ConsolidationMath::add((string) $link->synced_paid_amount, $native), (string) $link->gross_amount);
        $state = ConsolidationMath::cmp($paid, (string) $link->gross_amount) >= 0 ? PaymentState::Paid : PaymentState::Partial;

        $this->scope->runAs((string) $link->mirror_tenant_id, function () use ($link, $paid, $state): void {
            PassiveInvoice::query()->whereKey($link->mirror_document_id)->update(['total_paid' => $paid, 'payment_status' => $state->value]);
        });

        $link->forceFill(['synced_paid_amount' => $paid, 'payment_status' => $state->value, 'version' => $link->version + 1])->save();
        $this->log->record((string) $group->tenant_id, (string) $group->id, $link, SyncEventType::NettingApplied, null, ['netting_item_id' => $item->id, 'applied_native' => $native], $applied, $user->id);
    }

    /**
     * @return Collection<int, TransactionLink>
     */
    private function openLinks(ConsolidationGroup $group, string $source, string $mirror, CarbonImmutable $asOf): Collection
    {
        return TransactionLink::withoutGlobalScopes()
            ->where('tenant_id', $group->tenant_id)
            ->where('group_id', $group->id)
            ->where('source_tenant_id', $source)
            ->where('mirror_tenant_id', $mirror)
            ->whereIn('status', [LinkStatus::Drafted->value, LinkStatus::Confirmed->value])
            ->where('payment_status', '!=', PaymentState::Paid->value)
            ->whereDate('document_date', '<=', $asOf->toDateString())
            ->orderBy('document_date')
            ->get()
            ->filter(fn (TransactionLink $link): bool => ! ConsolidationMath::isZero($this->openAmount($link)))
            ->values();
    }

    private function openAmount(TransactionLink $link): string
    {
        $open = ConsolidationMath::sub((string) $link->gross_amount, (string) $link->synced_paid_amount);

        return $link->document_type === DocumentKind::CreditNote->value ? ConsolidationMath::neg($open) : $open;
    }

    private function rate(string $from, string $to, string $groupCurrency, CarbonImmutable $date): string
    {
        if ($from === $to) {
            return '1.00000000';
        }

        try {
            $fromRate = $from === $groupCurrency ? '1' : $this->translator->rateOn($from, $groupCurrency, $date);
            $toRate = $to === $groupCurrency ? '1' : $this->translator->rateOn($to, $groupCurrency, $date);
        } catch (ConsolidationException $exception) {
            throw new IntercompanyException($exception->getMessage(), 'rate_missing');
        }

        return bcdiv($fromRate, $toRate, 8);
    }

    private function assertDraft(NettingRun $run, ?int $expectedVersion): void
    {
        $current = NettingRun::withoutGlobalScopes()->whereKey($run->id)->first();

        if ($current === null || ($expectedVersion !== null && (int) $current->version !== $expectedVersion)) {
            throw new IntercompanyConflictException('The netting was changed by someone else: reload it and try again.', 'stale_version');
        }

        if ($current->status !== NettingStatus::Draft->value) {
            throw new IntercompanyConflictException('Only a draft netting can be confirmed or cancelled.', 'netting_not_draft');
        }

        $run->refresh();
    }
}
