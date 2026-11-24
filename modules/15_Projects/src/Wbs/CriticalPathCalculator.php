<?php

declare(strict_types=1);

namespace Modules\Projects\Wbs;

use Modules\Projects\Wbs\Enums\DependencyType;
use Modules\Projects\Wbs\Exceptions\CyclicDependencyException;

/**
 * Critical path method over integer day offsets (day 0 is the base date of the schedule).
 *
 * A node occupies the half-open interval [early start, early finish) with finish = start + duration, so a milestone
 * (duration 0) is a point. The four relations with a lag (negative for a lead) constrain the successor in the forward
 * pass and the predecessor in the backward pass:
 *
 *   FS  start(s) >= finish(p) + lag      SS  start(s) >= start(p) + lag
 *   FF  finish(s) >= finish(p) + lag     SF  finish(s) >= start(p) + lag
 *
 * `earliest_start` is a start-no-earlier-than constraint (the planned start of the node), which is what makes a late
 * predecessor push its successors later without ever pulling a node before its own planned start. The project finish is
 * the latest early finish; total float is late start minus early start, free float the delay a node tolerates before
 * pushing any successor, and a node is critical when its total float is zero or less.
 *
 * @phpstan-type NodeInput array{id: string, duration: int, earliest_start?: int|null}
 * @phpstan-type DependencyInput array{predecessor: string, successor: string, type: DependencyType, lag: int}
 * @phpstan-type Schedule array{es: int, ef: int, ls: int, lf: int, total_float: int, free_float: int, critical: bool}
 */
final class CriticalPathCalculator
{
    /**
     * @param  list<NodeInput>  $nodes
     * @param  list<DependencyInput>  $dependencies  links to unknown nodes are ignored
     * @return array{nodes: array<string, Schedule>, project_finish: int, critical_path: list<string>}
     *
     * @throws CyclicDependencyException when the network is not a DAG
     */
    public function calculate(array $nodes, array $dependencies): array
    {
        $duration = [];
        $snet = [];

        foreach ($nodes as $node) {
            $duration[$node['id']] = max(0, $node['duration']);
            $snet[$node['id']] = $node['earliest_start'] ?? 0;
        }

        $incoming = [];
        $outgoing = [];

        foreach ($dependencies as $dependency) {
            $p = $dependency['predecessor'];
            $s = $dependency['successor'];

            if ($p === $s || ! isset($duration[$p], $duration[$s])) {
                continue;
            }

            $incoming[$s][] = $dependency;
            $outgoing[$p][] = $dependency;
        }

        $order = $this->topologicalOrder(array_keys($duration), $incoming, $outgoing);

        $es = [];
        $ef = [];

        foreach ($order as $id) {
            $start = $snet[$id];

            foreach ($incoming[$id] ?? [] as $dependency) {
                $p = $dependency['predecessor'];
                $lag = $dependency['lag'];

                $start = max($start, match ($dependency['type']) {
                    DependencyType::FinishToStart => $ef[$p] + $lag,
                    DependencyType::StartToStart => $es[$p] + $lag,
                    DependencyType::FinishToFinish => $ef[$p] + $lag - $duration[$id],
                    DependencyType::StartToFinish => $es[$p] + $lag - $duration[$id],
                });
            }

            $es[$id] = $start;
            $ef[$id] = $start + $duration[$id];
        }

        $finish = $ef === [] ? 0 : max($ef);
        $ls = [];
        $lf = [];

        foreach (array_reverse($order) as $id) {
            $latestFinish = $finish;

            foreach ($outgoing[$id] ?? [] as $dependency) {
                $s = $dependency['successor'];
                $lag = $dependency['lag'];

                $latestFinish = min($latestFinish, match ($dependency['type']) {
                    DependencyType::FinishToStart => $ls[$s] - $lag,
                    DependencyType::StartToStart => $ls[$s] - $lag + $duration[$id],
                    DependencyType::FinishToFinish => $lf[$s] - $lag,
                    DependencyType::StartToFinish => $lf[$s] - $lag + $duration[$id],
                });
            }

            $lf[$id] = $latestFinish;
            $ls[$id] = $latestFinish - $duration[$id];
        }

        $schedule = [];

        foreach ($order as $id) {
            $totalFloat = $ls[$id] - $es[$id];
            $schedule[$id] = [
                'es' => $es[$id],
                'ef' => $ef[$id],
                'ls' => $ls[$id],
                'lf' => $lf[$id],
                'total_float' => $totalFloat,
                'free_float' => max(0, $this->freeFloat($id, $outgoing[$id] ?? [], $es, $ef, $finish)),
                'critical' => $totalFloat <= 0,
            ];
        }

        $critical = array_keys(array_filter($schedule, static fn (array $row): bool => $row['critical']));
        usort($critical, static fn (string $a, string $b): int => [$schedule[$a]['es'], $schedule[$a]['ef']] <=> [$schedule[$b]['es'], $schedule[$b]['ef']]);

        return ['nodes' => $schedule, 'project_finish' => $finish, 'critical_path' => $critical];
    }

    /**
     * Whether adding `predecessor -> successor` to the given links would close a cycle.
     *
     * @param  list<array{predecessor: string, successor: string}>  $dependencies
     */
    public function wouldCreateCycle(array $dependencies, string $predecessor, string $successor): bool
    {
        if ($predecessor === $successor) {
            return true;
        }

        $next = [];

        foreach ($dependencies as $dependency) {
            $next[$dependency['predecessor']][] = $dependency['successor'];
        }

        // A cycle appears when the predecessor is already reachable from the successor.
        $stack = [$successor];
        $seen = [$successor => true];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $predecessor) {
                return true;
            }

            foreach ($next[$current] ?? [] as $following) {
                if (! isset($seen[$following])) {
                    $seen[$following] = true;
                    $stack[] = $following;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, list<DependencyInput>>  $incoming
     * @param  array<string, list<DependencyInput>>  $outgoing
     * @return list<string>
     *
     * @throws CyclicDependencyException
     */
    private function topologicalOrder(array $ids, array $incoming, array $outgoing): array
    {
        $pending = [];
        $queue = [];

        foreach ($ids as $id) {
            $pending[$id] = count($incoming[$id] ?? []);

            if ($pending[$id] === 0) {
                $queue[] = $id;
            }
        }

        $order = [];

        for ($i = 0; $i < count($queue); $i++) {
            $id = $queue[$i];
            $order[] = $id;

            foreach ($outgoing[$id] ?? [] as $dependency) {
                $successor = $dependency['successor'];

                if (--$pending[$successor] === 0) {
                    $queue[] = $successor;
                }
            }
        }

        if (count($order) < count($ids)) {
            throw new CyclicDependencyException(array_values(array_diff($ids, $order)));
        }

        return $order;
    }

    /**
     * @param  list<DependencyInput>  $outgoing
     * @param  array<string, int>  $es
     * @param  array<string, int>  $ef
     */
    private function freeFloat(string $id, array $outgoing, array $es, array $ef, int $projectFinish): int
    {
        if ($outgoing === []) {
            return $projectFinish - $ef[$id];
        }

        $free = PHP_INT_MAX;

        foreach ($outgoing as $dependency) {
            $s = $dependency['successor'];
            $lag = $dependency['lag'];

            $free = min($free, match ($dependency['type']) {
                DependencyType::FinishToStart => $es[$s] - $lag - $ef[$id],
                DependencyType::StartToStart => $es[$s] - $lag - $es[$id],
                DependencyType::FinishToFinish => $ef[$s] - $lag - $ef[$id],
                DependencyType::StartToFinish => $ef[$s] - $lag - $es[$id],
            });
        }

        return $free;
    }
}
