<?php

declare(strict_types=1);

use Modules\Projects\Wbs\CriticalPathCalculator;
use Modules\Projects\Wbs\Enums\DependencyType;
use Modules\Projects\Wbs\Exceptions\CyclicDependencyException;

/**
 * @return array{id: string, duration: int, earliest_start: int}
 */
function cpmNode(string $id, int $duration, int $earliestStart = 0): array
{
    return ['id' => $id, 'duration' => $duration, 'earliest_start' => $earliestStart];
}

/**
 * @return array{predecessor: string, successor: string, type: DependencyType, lag: int}
 */
function cpmLink(string $predecessor, string $successor, DependencyType $type = DependencyType::FinishToStart, int $lag = 0): array
{
    return ['predecessor' => $predecessor, 'successor' => $successor, 'type' => $type, 'lag' => $lag];
}

test('forward and backward pass give early and late dates, floats and the critical path', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 3), cpmNode('b', 2), cpmNode('c', 4), cpmNode('d', 1)],
        [cpmLink('a', 'b'), cpmLink('a', 'c'), cpmLink('b', 'd'), cpmLink('c', 'd')],
    );

    expect($result['project_finish'])->toBe(8)
        ->and($result['critical_path'])->toBe(['a', 'c', 'd'])
        ->and($result['nodes']['a'])->toMatchArray(['es' => 0, 'ef' => 3, 'ls' => 0, 'lf' => 3, 'total_float' => 0, 'critical' => true])
        ->and($result['nodes']['c'])->toMatchArray(['es' => 3, 'ef' => 7, 'total_float' => 0, 'critical' => true])
        ->and($result['nodes']['d'])->toMatchArray(['es' => 7, 'ef' => 8, 'critical' => true])
        ->and($result['nodes']['b'])->toMatchArray(['es' => 3, 'ef' => 5, 'ls' => 5, 'lf' => 7, 'total_float' => 2, 'free_float' => 2, 'critical' => false]);
});

test('start-to-start with a lag delays the successor from the start of the predecessor', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 4), cpmNode('b', 3)],
        [cpmLink('a', 'b', DependencyType::StartToStart, 2)],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 2, 'ef' => 5, 'critical' => true])
        ->and($result['nodes']['a'])->toMatchArray(['es' => 0, 'ef' => 4, 'lf' => 4, 'total_float' => 0, 'critical' => true])
        ->and($result['project_finish'])->toBe(5);
});

test('finish-to-finish aligns the finishes: the successor starts as late as needed', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 5), cpmNode('b', 2)],
        [cpmLink('a', 'b', DependencyType::FinishToFinish)],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 3, 'ef' => 5])
        ->and($result['project_finish'])->toBe(5);
});

test('start-to-finish constrains the finish of the successor by the start of the predecessor', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 3, 4), cpmNode('b', 2)],
        [cpmLink('a', 'b', DependencyType::StartToFinish)],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 2, 'ef' => 4, 'total_float' => 3, 'critical' => false])
        ->and($result['nodes']['a'])->toMatchArray(['es' => 4, 'ef' => 7, 'total_float' => 0, 'critical' => true])
        ->and($result['project_finish'])->toBe(7);
});

test('a negative lag (lead) lets the successor start before the predecessor finishes', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 4), cpmNode('b', 3)],
        [cpmLink('a', 'b', DependencyType::FinishToStart, -2)],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 2, 'ef' => 5])
        ->and($result['project_finish'])->toBe(5)
        ->and($result['critical_path'])->toBe(['a', 'b']);
});

test('a positive lag adds waiting time between the nodes', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 2), cpmNode('b', 2)],
        [cpmLink('a', 'b', DependencyType::FinishToStart, 3)],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 5, 'ef' => 7])->and($result['project_finish'])->toBe(7);
});

test('a milestone has no duration and follows its predecessor', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 3), cpmNode('m', 0)],
        [cpmLink('a', 'm')],
    );

    expect($result['nodes']['m'])->toMatchArray(['es' => 3, 'ef' => 3, 'critical' => true]);
});

test('the planned start of a node is a start-no-earlier-than constraint and the early nodes gain float', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 2), cpmNode('b', 2, 10)],
        [cpmLink('a', 'b')],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 10, 'ef' => 12, 'critical' => true])
        ->and($result['nodes']['a'])->toMatchArray(['es' => 0, 'ls' => 8, 'total_float' => 8, 'free_float' => 8, 'critical' => false])
        ->and($result['critical_path'])->toBe(['b']);
});

test('a node without links keeps its own dates and free float runs to the project finish', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 10), cpmNode('b', 3, 2)],
        [],
    );

    expect($result['nodes']['b'])->toMatchArray(['es' => 2, 'ef' => 5, 'total_float' => 5, 'free_float' => 5, 'critical' => false])
        ->and($result['nodes']['a']['critical'])->toBeTrue();
});

test('links to unknown nodes and self links are ignored', function (): void {
    $result = (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 2)],
        [cpmLink('a', 'ghost'), cpmLink('ghost', 'a'), cpmLink('a', 'a')],
    );

    expect($result['nodes']['a'])->toMatchArray(['es' => 0, 'ef' => 2]);
});

test('an empty network has no critical path', function (): void {
    expect((new CriticalPathCalculator)->calculate([], []))->toBe(['nodes' => [], 'project_finish' => 0, 'critical_path' => []]);
});

test('a cycle in the network is detected and names the nodes involved', function (): void {
    $calculate = fn () => (new CriticalPathCalculator)->calculate(
        [cpmNode('a', 1), cpmNode('b', 1), cpmNode('c', 1), cpmNode('free', 1)],
        [cpmLink('a', 'b'), cpmLink('b', 'c'), cpmLink('c', 'a')],
    );

    expect($calculate)->toThrow(CyclicDependencyException::class);

    try {
        $calculate();
    } catch (CyclicDependencyException $e) {
        expect($e->nodeIds)->toEqualCanonicalizing(['a', 'b', 'c']);
    }
});

test('wouldCreateCycle tells whether a new link closes a loop', function (): void {
    $links = [['predecessor' => 'a', 'successor' => 'b'], ['predecessor' => 'b', 'successor' => 'c']];
    $calculator = new CriticalPathCalculator;

    expect($calculator->wouldCreateCycle($links, 'c', 'a'))->toBeTrue()
        ->and($calculator->wouldCreateCycle($links, 'b', 'a'))->toBeTrue()
        ->and($calculator->wouldCreateCycle($links, 'a', 'a'))->toBeTrue()
        ->and($calculator->wouldCreateCycle($links, 'a', 'c'))->toBeFalse()
        ->and($calculator->wouldCreateCycle($links, 'x', 'a'))->toBeFalse();
});
