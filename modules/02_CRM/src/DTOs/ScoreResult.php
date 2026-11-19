<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class ScoreResult
{
    /**
     * @param  list<array{rule_id: ?string, code: string, points: int, applications: int}>  $breakdown
     */
    public function __construct(
        public int $score,
        public string $rating,
        public array $breakdown,
    ) {}
}
