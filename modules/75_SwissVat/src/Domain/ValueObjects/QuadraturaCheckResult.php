<?php

declare(strict_types=1);

namespace Modules\SwissVat\Domain\ValueObjects;

/**
 * Result of a single accounting quadratura invariant check on a Swiss VAT return.
 */
final readonly class QuadraturaCheckResult
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public string $code,
        public string $severity,
        public bool $passed,
        public string $expected,
        public string $actual,
        public string $difference,
        public string $messageKey,
        public array $params = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'passed' => $this->passed,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'difference' => $this->difference,
            'message_key' => $this->messageKey,
            'message' => __($this->messageKey, $this->params),
            'params' => $this->params,
        ];
    }
}
