<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services\Projections;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;

/**
 * Fixed assets: cost, accumulated depreciation and carrying amount per asset. Events: AssetAcquired {asset_id, asset_number, name, cost},
 * AssetDepreciated {asset_id, depreciation_amount}, AssetRevalued and AssetImpaired {asset_id, adjustment (signed change of the
 * carrying amount)}, AssetDisposed {asset_id, proceeds, gain_loss}.
 */
final class FixedAssetsProjector implements StateProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::FixedAssets;
    }

    public function checkpointDomain(): QueryDomain
    {
        return QueryDomain::FixedAssets;
    }

    public function usesCheckpoint(QueryDomain $domain): bool
    {
        return true;
    }

    public function initialState(QueryDomain $domain, array $filters): array
    {
        return ['assets' => []];
    }

    public function apply(array $state, BiTemporalEvent $event): array
    {
        $payload = $event->payload;
        $id = (string) ($payload['asset_id'] ?? $event->aggregate_id);
        $asset = $state['assets'][$id] ?? ['asset_number' => '', 'name' => '', 'cost' => '0', 'accumulated_depreciation' => '0', 'adjustments' => '0', 'disposed' => false];

        switch ($event->event_type) {
            case 'AssetAcquired':
                $asset['asset_number'] = (string) ($payload['asset_number'] ?? $asset['asset_number']);
                $asset['name'] = (string) ($payload['name'] ?? $asset['name']);
                $asset['cost'] = Amount::add($asset['cost'], $payload['cost'] ?? 0);
                break;
            case 'AssetDepreciated':
                $asset['accumulated_depreciation'] = Amount::add($asset['accumulated_depreciation'], $payload['depreciation_amount'] ?? 0);
                break;
            case 'AssetRevalued':
            case 'AssetImpaired':
                $asset['adjustments'] = Amount::add($asset['adjustments'], $payload['adjustment'] ?? 0);
                break;
            case 'AssetDisposed':
                $asset['disposed'] = true;
                break;
        }

        $state['assets'][$id] = $asset;

        return $state;
    }

    public function render(QueryDomain $domain, array $state, array $filters, CarbonImmutable $validAt, string $tenantId): array
    {
        $rows = [];
        $cost = '0';
        $accumulated = '0';
        $carrying = '0';

        /** @var array<string, array<string, mixed>> $assets */
        $assets = $state['assets'];

        foreach ($assets as $id => $asset) {
            $assetCarrying = $asset['disposed'] ? '0' : Amount::add(Amount::sub($asset['cost'], $asset['accumulated_depreciation']), $asset['adjustments']);
            $rows[] = [
                'asset_id' => (string) $id,
                'asset_number' => $asset['asset_number'],
                'name' => $asset['name'],
                'cost' => Amount::money($asset['cost']),
                'accumulated_depreciation' => Amount::money($asset['accumulated_depreciation']),
                'carrying_amount' => Amount::money($assetCarrying),
                'status' => $asset['disposed'] ? 'disposed' : 'active',
            ];

            if (! $asset['disposed']) {
                $cost = Amount::add($cost, $asset['cost']);
                $accumulated = Amount::add($accumulated, $asset['accumulated_depreciation']);
                $carrying = Amount::add($carrying, $assetCarrying);
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['asset_number'], $a['asset_id']] <=> [$b['asset_number'], $b['asset_id']]);

        return ['rows' => $rows, 'totals' => ['cost' => Amount::money($cost), 'accumulated_depreciation' => Amount::money($accumulated), 'carrying_amount' => Amount::money($carrying)]];
    }

    public function comparable(QueryDomain $domain, array $rows): array
    {
        $comparable = [];

        foreach ($rows as $row) {
            $comparable[(string) $row['asset_id']] = [
                'label' => (string) ($row['asset_number'] !== '' ? $row['asset_number'].' '.$row['name'] : $row['asset_id']),
                'value' => (string) $row['carrying_amount'],
                'weight' => (string) $row['carrying_amount'],
            ];
        }

        return $comparable;
    }

    public function matches(QueryDomain $domain, BiTemporalEvent $event, array $filters): bool
    {
        return true;
    }

    public function affectedKeys(QueryDomain $domain, BiTemporalEvent $event, array $filters): array
    {
        return [(string) ($event->payload['asset_id'] ?? $event->aggregate_id)];
    }

    public function amountDelta(QueryDomain $domain, BiTemporalEvent $event, array $filters): string
    {
        $payload = $event->payload;

        return Amount::money(match ($event->event_type) {
            'AssetAcquired' => $payload['cost'] ?? 0,
            'AssetDepreciated' => Amount::neg($payload['depreciation_amount'] ?? 0),
            'AssetRevalued', 'AssetImpaired' => $payload['adjustment'] ?? 0,
            default => 0,
        });
    }

    public function sourceDocument(BiTemporalEvent $event): ?string
    {
        $number = (string) ($event->payload['asset_number'] ?? '');

        return $number !== '' ? $number : (isset($event->payload['asset_id']) ? (string) $event->payload['asset_id'] : null);
    }
}
