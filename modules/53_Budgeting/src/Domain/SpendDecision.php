<?php

declare(strict_types=1);

namespace Modules\Budgeting\Domain;

/**
 * The outcome of a spend control: ok (inside the budget or no rule applies), warning (over the budget, allowed with a
 * warning), overridden (over the budget, blocked but lifted by an approved override) or blocked.
 */
final readonly class SpendDecision
{
    public const string Ok = 'ok';

    public const string Warning = 'warning';

    public const string Overridden = 'overridden';

    public const string Blocked = 'blocked';

    public function __construct(
        public string $outcome,
        public Availability $availability,
        public string $requested,
        public string $shortfall,
        public ?string $ruleId,
        public ?string $mode,
        public ?string $overrideId,
        public ?string $checkId = null,
    ) {}

    public function isBlocked(): bool
    {
        return $this->outcome === self::Blocked;
    }

    public function withCheck(string $checkId): self
    {
        return new self($this->outcome, $this->availability, $this->requested, $this->shortfall, $this->ruleId, $this->mode, $this->overrideId, $checkId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'mode' => $this->mode,
            'rule_id' => $this->ruleId,
            'override_id' => $this->overrideId,
            'check_id' => $this->checkId,
            'requested' => $this->requested,
            'shortfall' => $this->shortfall,
            'availability' => $this->availability->toArray(),
        ];
    }
}
