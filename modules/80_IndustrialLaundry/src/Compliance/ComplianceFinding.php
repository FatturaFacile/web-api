<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Modules\IndustrialLaundry\Domain\Enums\FindingSeverity;

/**
 * What a compliance strategy found: a code (industrialLaundry.finding.<code> in the frontend dictionary), a severity and the
 * parameters the text needs. A blocking finding stops the garment or the batch; a warning only reports.
 */
final readonly class ComplianceFinding
{
    /**
     * @param  array<string, string|int|float|null>  $params
     */
    public function __construct(
        public string $code,
        public FindingSeverity $severity,
        public array $params = [],
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === FindingSeverity::Blocking;
    }

    /**
     * @return array{code: string, severity: string, params: array<string, string|int|float|null>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity->value, 'params' => $this->params];
    }
}
