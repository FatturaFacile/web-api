<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\ValueObjects;

/**
 * The verdict of the HSE hard-block gatekeeper for one worker, target and moment.
 */
final readonly class ClearanceResult
{
    /**
     * @param  list<BlockingReason>  $blockingReasons
     * @param  list<string>  $warnings  operational prescriptions the dispatcher must respect (a cleared worker may still carry them)
     */
    public function __construct(
        public bool $isCleared,
        public array $blockingReasons = [],
        public array $warnings = [],
    ) {}

    /**
     * @param  list<BlockingReason>  $reasons
     * @param  list<string>  $warnings
     */
    public static function fromReasons(array $reasons, array $warnings = []): self
    {
        return new self($reasons === [], $reasons, $warnings);
    }

    /**
     * @return array{is_cleared: bool, blocking_reasons: list<array<string, string|null>>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'is_cleared' => $this->isCleared,
            'blocking_reasons' => array_map(static fn (BlockingReason $reason): array => $reason->toArray(), $this->blockingReasons),
            'warnings' => $this->warnings,
        ];
    }
}
