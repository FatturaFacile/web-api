<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Commands;

/**
 * Enrolment of a student in a weekly group course.
 */
final readonly class EnrollStudentCommand
{
    /**
     * @param  list<array<string, mixed>>  $items  package items (see PackageHandler)
     */
    public function __construct(
        public string $courseId,
        public string $studentName,
        public ?string $birthDate = null,
        public ?string $contactId = null,
        public ?string $guardianName = null,
        public ?string $guardianEmail = null,
        public ?string $emergencyContactName = null,
        public ?string $emergencyContactPhone = null,
        public ?float $priceNet = null,
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
            courseId: (string) $data['course_id'],
            studentName: (string) $data['student_name'],
            birthDate: isset($data['birth_date']) && $data['birth_date'] !== '' ? (string) $data['birth_date'] : null,
            contactId: isset($data['contact_id']) && $data['contact_id'] !== '' ? (string) $data['contact_id'] : null,
            guardianName: isset($data['guardian_name']) && $data['guardian_name'] !== '' ? (string) $data['guardian_name'] : null,
            guardianEmail: isset($data['guardian_email']) && $data['guardian_email'] !== '' ? (string) $data['guardian_email'] : null,
            emergencyContactName: isset($data['emergency_contact_name']) && $data['emergency_contact_name'] !== '' ? (string) $data['emergency_contact_name'] : null,
            emergencyContactPhone: isset($data['emergency_contact_phone']) && $data['emergency_contact_phone'] !== '' ? (string) $data['emergency_contact_phone'] : null,
            priceNet: isset($data['price_net']) && $data['price_net'] !== '' ? (float) $data['price_net'] : null,
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
            source: (string) ($data['source'] ?? 'BACK_OFFICE'),
            items: array_values((array) ($data['items'] ?? [])),
            userId: $userId,
        );
    }
}
