<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Modules\Payroll\Exceptions\PayrollException;

/**
 * The parameters of a topic found for a year, with the year they really belong to.
 */
final readonly class LegalParameterSet
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $topic,
        public int $year,
        public array $values,
        public string $source,
        public array $warnings,
    ) {}

    /**
     * A value the calculation cannot go without: a missing one stops it, nothing is assumed in its place.
     */
    public function required(string $key): mixed
    {
        return $this->values[$key] ?? throw new PayrollException("Parametro legale {$this->topic}.{$key} mancante per il {$this->year}: va impostato dalla fonte ufficiale prima di procedere.");
    }

    public function optional(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
