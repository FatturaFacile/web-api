<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Batch-records a safety briefing and the on-glass signatures captured on the mobile app.
 */
final readonly class RecordToolBoxTalkAttendanceCommand
{
    /**
     * @param  list<string>  $checklistPoints
     * @param  list<array{employee_id: string, signature_payload: string, signature_image: string|null, signed_at: CarbonImmutable}>  $attendances
     */
    public function __construct(
        public string $tenantId,
        public string $topic,
        public ?string $siteOrWorkCenter,
        public array $checklistPoints,
        public ?string $supervisorEmployeeId,
        public CarbonImmutable $conductedAt,
        public array $attendances,
        public ?string $userId,
        public ?string $talkId = null,
    ) {}
}
