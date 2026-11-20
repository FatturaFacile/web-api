<?php

declare(strict_types=1);

namespace Modules\Academy\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * The completion rule of a mission: the ERP domain event that is awaited and a predicate on its payload.
 *
 * The predicate is data, never code. Grammar (parsed and evaluated by VerificationRuleEvaluator, no eval):
 *
 *   predicate := conjunction ( " OR " conjunction )*
 *   conjunction := clause ( " AND " clause )*
 *   clause := path operator literal | path "exists"
 *   path := dot-notation key of the event payload, e.g. "total_gross" or "lines.0.quantity"
 *   operator := == != >= <= > < in not_in contains exists
 *   literal := JSON value, e.g. 100, "paid", true, ["draft","sent"]
 *
 * An empty predicate matches every event of the target type. A rule marked sandbox-only is evaluated and can be started
 * only in sandbox (Mod 105 demo) tenants.
 */
final readonly class VerificationRuleSpec
{
    public function __construct(
        public string $targetEvent,
        public string $predicate = '',
        public bool $sandboxOnly = true,
    ) {
        if (trim($targetEvent) === '') {
            throw new InvalidArgumentException('A verification rule needs a target domain event.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            targetEvent: (string) ($data['target_event'] ?? ''),
            predicate: (string) ($data['predicate'] ?? ''),
            sandboxOnly: (bool) ($data['sandbox_only'] ?? true),
        );
    }

    /**
     * @return array{target_event: string, predicate: string, sandbox_only: bool}
     */
    public function toArray(): array
    {
        return ['target_event' => $this->targetEvent, 'predicate' => $this->predicate, 'sandbox_only' => $this->sandboxOnly];
    }
}
