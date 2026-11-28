<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Commands;

/**
 * Generation of the monthly payout statements of the instructors.
 */
final readonly class GeneratePayoutCommand
{
    public function __construct(
        public string $month,
        public ?string $instructorId = null,
        public ?string $userId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            month: (string) $data['month'],
            instructorId: isset($data['instructor_id']) && $data['instructor_id'] !== '' ? (string) $data['instructor_id'] : null,
            userId: $userId,
        );
    }
}
