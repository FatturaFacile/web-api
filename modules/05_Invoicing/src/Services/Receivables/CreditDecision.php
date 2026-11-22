<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

/**
 * Outcome of a credit check for one operation (confirming an order, issuing a delivery note): `ok`, `warning` (go on,
 * alert the desk) or `block`. An authorised override turns a block or a warning into `ok` and says so.
 */
final readonly class CreditDecision
{
    public const string Ok = 'ok';

    public const string Warning = 'warning';

    public const string Block = 'block';

    /**
     * @param  list<string>  $breaches  `over_limit`, `overdue_days`, `credit_hold`
     */
    public function __construct(
        public string $outcome,
        public array $breaches,
        public ?string $reason,
        public string $exposure,
        public string $limit,
        public bool $overridden = false,
        public ?string $reactionPolicy = null,
    ) {}

    public function blocks(): bool
    {
        return $this->outcome === self::Block;
    }

    public function warns(): bool
    {
        return $this->outcome === self::Warning;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'breaches' => $this->breaches,
            'reason' => $this->reason,
            'exposure' => $this->exposure,
            'limit' => $this->limit,
            'overridden' => $this->overridden,
            'reaction_policy' => $this->reactionPolicy,
        ];
    }
}
