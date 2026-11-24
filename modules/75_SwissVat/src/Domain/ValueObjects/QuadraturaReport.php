<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\ValueObjects;

/**
 * Aggregated Swiss VAT quadratura report across all accounting checks (Q1..Q9).
 */
final readonly class QuadraturaReport
{
    /**
     * @param  list<QuadraturaCheckResult>  $checks
     */
    public function __construct(
        public bool $passed,
        public array $checks,
    ) {}

    /**
     * @return list<QuadraturaCheckResult>
     */
    public function blockers(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (QuadraturaCheckResult $c): bool => ! $c->passed && $c->severity === 'blocker',
        ));
    }

    /**
     * @return list<QuadraturaCheckResult>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (QuadraturaCheckResult $c): bool => ! $c->passed && $c->severity === 'warning',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'checks' => array_map(static fn (QuadraturaCheckResult $c): array => $c->toArray(), $this->checks),
            'blockers_count' => count($this->blockers()),
            'warnings_count' => count($this->warnings()),
            'blockers' => array_map(static fn (QuadraturaCheckResult $c): array => $c->toArray(), $this->blockers()),
            'warnings' => array_map(static fn (QuadraturaCheckResult $c): array => $c->toArray(), $this->warnings()),
        ];
    }
}
