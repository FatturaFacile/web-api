<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Support;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Turns the flows of the horizon into the rolling cash projection: a daily series and a weekly series (opening balance,
 * inflows, outflows, net flow and closing balance, split by reliability tier), the summaries of the 30, 60, 90 and 180 day
 * horizons and the first day the balance turns negative. Flows dated before the start (overdue) fall on the first day.
 */
final class ProjectionBuilder
{
    /** @var list<int> */
    public const array Horizons = [30, 60, 90, 180];

    public const int MaxDays = 180;

    /**
     * @param  list<Flow>  $flows
     * @return array{daily: list<array<string, mixed>>, weekly: list<array<string, mixed>>, negative_balance: array{first_date: string, min_balance: string, min_date: string}|null}
     */
    public function build(string $opening, array $flows, CarbonImmutable $start, int $days = self::MaxDays): array
    {
        $end = $start->addDays($days);
        $byDay = [];

        foreach ($flows as $flow) {
            $date = $flow->date->startOfDay()->max($start);

            if ($date->greaterThan($end)) {
                continue;
            }

            $key = $date->toDateString();
            $side = $flow->isInflow() ? 'inflows' : 'outflows';
            $byDay[$key][$flow->tier][$side] = Decimal::add($byDay[$key][$flow->tier][$side] ?? '0', $flow->amount);
        }

        $daily = [];
        $running = Decimal::round2($opening);
        $firstNegative = null;
        $lowest = ['date' => $start->toDateString(), 'balance' => $running];

        for ($offset = 0; $offset <= $days; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $tiers = $this->emptyTiers();
            $inflows = '0';
            $outflows = '0';

            foreach (Flow::Tiers as $tier) {
                $in = Decimal::round2($byDay[$date][$tier]['inflows'] ?? '0');
                $out = Decimal::round2($byDay[$date][$tier]['outflows'] ?? '0');
                $tiers[$tier] = ['inflows' => $in, 'outflows' => $out];
                $inflows = Decimal::add($inflows, $in);
                $outflows = Decimal::add($outflows, $out);
            }

            $net = Decimal::round2(Decimal::sub($inflows, $outflows));
            $running = Decimal::round2(Decimal::add($running, $net));
            $daily[] = ['date' => $date, 'inflows' => Decimal::round2($inflows), 'outflows' => Decimal::round2($outflows), 'net' => $net, 'closing' => $running, 'tiers' => $tiers];

            if (Decimal::compare($running, $lowest['balance']) < 0) {
                $lowest = ['date' => $date, 'balance' => $running];
            }

            if ($firstNegative === null && Decimal::isNegative($running)) {
                $firstNegative = $date;
            }
        }

        return [
            'daily' => $daily,
            'weekly' => $this->weekly($daily),
            'negative_balance' => $firstNegative === null ? null : ['first_date' => $firstNegative, 'min_balance' => $lowest['balance'], 'min_date' => $lowest['date']],
        ];
    }

    /**
     * The summary of each horizon: inflows, outflows, net flow, closing and lowest balance.
     *
     * @param  list<array<string, mixed>>  $daily
     * @return list<array<string, mixed>>
     */
    public function horizons(string $opening, array $daily): array
    {
        $summaries = [];

        foreach (self::Horizons as $horizon) {
            $inflows = '0';
            $outflows = '0';
            $tiers = $this->emptyTiers();
            $min = Decimal::round2($opening);
            $minDate = (string) $daily[0]['date'];
            $closing = $min;
            $until = $minDate;

            foreach ($daily as $index => $point) {
                if ($index > $horizon) {
                    break;
                }

                $inflows = Decimal::add($inflows, (string) $point['inflows']);
                $outflows = Decimal::add($outflows, (string) $point['outflows']);
                $closing = (string) $point['closing'];
                $until = (string) $point['date'];

                foreach (Flow::Tiers as $tier) {
                    $tiers[$tier]['inflows'] = Decimal::round2(Decimal::add($tiers[$tier]['inflows'], (string) $point['tiers'][$tier]['inflows']));
                    $tiers[$tier]['outflows'] = Decimal::round2(Decimal::add($tiers[$tier]['outflows'], (string) $point['tiers'][$tier]['outflows']));
                }

                if (Decimal::compare($closing, $min) < 0) {
                    $min = $closing;
                    $minDate = $until;
                }
            }

            $summaries[] = [
                'days' => $horizon,
                'until' => $until,
                'inflows' => Decimal::round2($inflows),
                'outflows' => Decimal::round2($outflows),
                'net' => Decimal::round2(Decimal::sub($inflows, $outflows)),
                'closing_balance' => $closing,
                'min_balance' => $min,
                'min_date' => $minDate,
                'tiers' => $tiers,
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @return list<array<string, mixed>>
     */
    private function weekly(array $daily): array
    {
        $weeks = [];

        foreach (array_chunk($daily, 7) as $chunk) {
            $inflows = '0';
            $outflows = '0';
            $tiers = $this->emptyTiers();

            foreach ($chunk as $point) {
                $inflows = Decimal::add($inflows, (string) $point['inflows']);
                $outflows = Decimal::add($outflows, (string) $point['outflows']);

                foreach (Flow::Tiers as $tier) {
                    $tiers[$tier]['inflows'] = Decimal::round2(Decimal::add($tiers[$tier]['inflows'], (string) $point['tiers'][$tier]['inflows']));
                    $tiers[$tier]['outflows'] = Decimal::round2(Decimal::add($tiers[$tier]['outflows'], (string) $point['tiers'][$tier]['outflows']));
                }
            }

            $last = $chunk[count($chunk) - 1];
            $weeks[] = [
                'date' => $chunk[0]['date'],
                'week_end' => $last['date'],
                'inflows' => Decimal::round2($inflows),
                'outflows' => Decimal::round2($outflows),
                'net' => Decimal::round2(Decimal::sub($inflows, $outflows)),
                'closing' => $last['closing'],
                'tiers' => $tiers,
            ];
        }

        return $weeks;
    }

    /**
     * @return array<string, array{inflows: string, outflows: string}>
     */
    private function emptyTiers(): array
    {
        $tiers = [];

        foreach (Flow::Tiers as $tier) {
            $tiers[$tier] = ['inflows' => '0.00', 'outflows' => '0.00'];
        }

        return $tiers;
    }
}
