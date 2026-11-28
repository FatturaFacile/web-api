<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Commands;

/**
 * A private lesson booking: slot, discipline, language, customer, the instructor choice and the optional package items.
 */
final readonly class BookLessonCommand
{
    /**
     * @param  list<array<string, mixed>>  $items  package items (see PackageHandler)
     */
    public function __construct(
        public string $discipline,
        public string $language,
        public string $lessonDate,
        public string $startTime,
        public int $slotMinutes,
        public int $slotCount,
        public int $participants,
        public string $customerName,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public ?string $customerContactId = null,
        public ?string $levelId = null,
        public ?string $requestedInstructorId = null,
        public ?string $instructorId = null,
        public bool $autoAssign = false,
        public ?float $priceNet = null,
        public ?string $meetingPoint = null,
        public ?string $notes = null,
        public string $source = 'BACK_OFFICE',
        public array $items = [],
        public ?string $userId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            discipline: (string) $data['discipline'],
            language: strtoupper((string) $data['language_code']),
            lessonDate: (string) $data['lesson_date'],
            startTime: (string) $data['start_time'],
            slotMinutes: (int) $data['slot_minutes'],
            slotCount: (int) ($data['slot_count'] ?? 1),
            participants: (int) ($data['participants'] ?? 1),
            customerName: (string) $data['customer_name'],
            customerEmail: isset($data['customer_email']) && $data['customer_email'] !== '' ? (string) $data['customer_email'] : null,
            customerPhone: isset($data['customer_phone']) && $data['customer_phone'] !== '' ? (string) $data['customer_phone'] : null,
            customerContactId: isset($data['customer_contact_id']) && $data['customer_contact_id'] !== '' ? (string) $data['customer_contact_id'] : null,
            levelId: isset($data['level_id']) && $data['level_id'] !== '' ? (string) $data['level_id'] : null,
            requestedInstructorId: isset($data['requested_instructor_id']) && $data['requested_instructor_id'] !== '' ? (string) $data['requested_instructor_id'] : null,
            instructorId: isset($data['instructor_id']) && $data['instructor_id'] !== '' ? (string) $data['instructor_id'] : null,
            autoAssign: (bool) ($data['auto_assign'] ?? false),
            priceNet: isset($data['price_net']) && $data['price_net'] !== '' ? (float) $data['price_net'] : null,
            meetingPoint: isset($data['meeting_point']) && $data['meeting_point'] !== '' ? (string) $data['meeting_point'] : null,
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
            source: (string) ($data['source'] ?? 'BACK_OFFICE'),
            items: array_values((array) ($data['items'] ?? [])),
            userId: $userId,
        );
    }
}
