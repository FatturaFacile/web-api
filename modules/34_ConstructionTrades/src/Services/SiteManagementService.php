<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ConstructionSite;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ConstructionTrades\Models\Bid;
use Modules\ConstructionTrades\Models\DiaryEntry;
use Modules\ConstructionTrades\Models\SiteDocument;
use Modules\ConstructionTrades\Models\Subcontract;
use Modules\ConstructionTrades\Models\SubcontractCertification;
use Modules\ConstructionTrades\Models\Subcontractor;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * What happens on a site besides the bid: the subcontracts and the work certified to subcontractors (flowing into the
 * job costing of the bid they belong to), the daily site diary, and the safety and compliance documents with their
 * expiry. Amounts are decimal strings computed with bcmath.
 */
final class SiteManagementService
{
    public function __construct(
        private readonly TradesService $trades,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Entrusts work on a site to an active subcontractor. A contract tied to a bid must refer to the same site as the
     * bid, so the certified work lands in the right job costing.
     *
     * @param  array{subcontractor_id: string, bid_id?: ?string, description: string, contract_amount: string, starts_on?: ?string, ends_on?: ?string}  $data
     */
    public function createSubcontract(ConstructionSite $site, array $data): Subcontract
    {
        return DB::transaction(function () use ($site, $data): Subcontract {
            $subcontractor = Subcontractor::query()->lockForUpdate()->findOrFail($data['subcontractor_id']);

            if ($subcontractor->status !== 'active') {
                throw new BusinessRuleException('A suspended subcontractor takes no new subcontract.');
            }

            $bidId = $data['bid_id'] ?? null;

            if ($bidId !== null) {
                $bid = Bid::query()->findOrFail($bidId);

                if ($bid->construction_site_id !== null && $bid->construction_site_id !== $site->id) {
                    throw new BusinessRuleException('The bid belongs to another construction site.');
                }
            }

            $subcontract = new Subcontract;
            $subcontract->fill([...$data, 'construction_site_id' => $site->id, 'bid_id' => $bidId, 'status' => 'active']);
            $subcontract->save();

            return $subcontract;
        });
    }

    /**
     * Closes an active subcontract: completed when the work is done, cancelled when it is not going ahead. Certified
     * work stays as recorded.
     */
    public function closeSubcontract(Subcontract $subcontract, string $status): Subcontract
    {
        return DB::transaction(function () use ($subcontract, $status): Subcontract {
            $locked = Subcontract::query()->lockForUpdate()->findOrFail($subcontract->id);

            if ($locked->status !== 'active') {
                throw new BusinessRuleException('Only an active subcontract can be closed.');
            }

            $locked->update(['status' => $status]);

            return $locked;
        });
    }

    /**
     * Certifies work of a subcontractor, never beyond the contract amount. When the contract belongs to a bid the amount
     * is booked as a site cost of that bid, so the budget control sees it.
     *
     * @param  array{certified_on: string, amount: string, description: string}  $data
     */
    public function certify(Subcontract $subcontract, array $data): SubcontractCertification
    {
        return DB::transaction(function () use ($subcontract, $data): SubcontractCertification {
            $locked = Subcontract::query()->lockForUpdate()->findOrFail($subcontract->id);

            if ($locked->status !== 'active') {
                throw new BusinessRuleException('Work is certified only on an active subcontract.');
            }

            $certified = $this->certifiedTotal($locked);
            $remaining = Decimal::sub((string) $locked->contract_amount, $certified);

            if (Decimal::compare((string) $data['amount'], $remaining) > 0) {
                throw new BusinessRuleException('The certification exceeds the remaining contract amount of '.Decimal::round2($remaining).'.');
            }

            $cost = $locked->bid_id === null ? null : $this->trades->recordCost(Bid::query()->findOrFail($locked->bid_id), [
                'cost_type' => 'other',
                'cost_date' => $data['certified_on'],
                'description' => $data['description'],
                'quantity' => '1',
                'unit_cost' => (string) $data['amount'],
            ]);

            $certification = new SubcontractCertification;
            $certification->fill([...$data, 'subcontract_id' => $locked->id, 'site_cost_id' => $cost?->id]);
            $certification->save();

            return $certification;
        });
    }

    /**
     * The contract with what is certified and what is left.
     *
     * @return array{contract_amount: string, certified_amount: string, remaining_amount: string}
     */
    public function subcontractTotals(Subcontract $subcontract): array
    {
        $certified = $this->certifiedTotal($subcontract);

        return [
            'contract_amount' => Decimal::round2((string) $subcontract->contract_amount),
            'certified_amount' => Decimal::round2($certified),
            'remaining_amount' => Decimal::round2(Decimal::sub((string) $subcontract->contract_amount, $certified)),
        ];
    }

    /**
     * Writes the diary of a day, once per site and day.
     *
     * @param  array{entry_date: string, weather?: ?string, workers_present: int, work_performed: string, incidents?: ?string}  $data
     */
    public function writeDiary(ConstructionSite $site, array $data, ?string $userId): DiaryEntry
    {
        return DB::transaction(function () use ($site, $data, $userId): DiaryEntry {
            ConstructionSite::query()->lockForUpdate()->findOrFail($site->id);

            if (DiaryEntry::query()->where('construction_site_id', $site->id)->whereDate('entry_date', $data['entry_date'])->exists()) {
                throw new BusinessRuleException('The diary of this day is already written: change it instead.');
            }

            $entry = new DiaryEntry;
            $entry->fill([...$data, 'construction_site_id' => $site->id, 'recorded_by' => $userId]);
            $entry->save();

            return $entry;
        });
    }

    /**
     * Safety and compliance documents that are expired or expire within the given days, soonest first. A document
     * without an expiry never appears.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringDocuments(int $withinDays): array
    {
        $today = $this->clock->today();
        $limit = $today->addDays($withinDays)->toDateString();
        $documents = SiteDocument::query()->whereNotNull('expires_on')->whereDate('expires_on', '<=', $limit)->with('subcontractor')->orderBy('expires_on')->get();

        return $documents->map(static fn (SiteDocument $document): array => [
            ...$document->only(['id', 'construction_site_id', 'subcontractor_id', 'kind', 'title']),
            'subcontractor_name' => $document->subcontractor?->name,
            'expires_on' => $document->expires_on?->toDateString(),
            'expired' => $document->expires_on !== null && $document->expires_on->toDateString() < $today->toDateString(),
            'days_left' => (int) CarbonImmutable::parse($today->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse((string) $document->expires_on?->toDateString(), 'UTC'), false),
        ])->values()->all();
    }

    /**
     * @return Collection<int, SubcontractCertification>
     */
    public function certifications(Subcontract $subcontract): Collection
    {
        return SubcontractCertification::query()->where('subcontract_id', $subcontract->id)->orderBy('certified_on')->get();
    }

    private function certifiedTotal(Subcontract $subcontract): string
    {
        return (string) SubcontractCertification::query()->where('subcontract_id', $subcontract->id)->get()
            ->reduce(static fn (string $sum, SubcontractCertification $row): string => Decimal::add($sum, (string) $row->amount), '0');
    }
}
