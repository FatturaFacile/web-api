<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Commands;

use Modules\OliveMillAndGrainMill\Domain\Enums\Certification;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\ProcessingMode;

/**
 * Books and optionally weighs a crop delivery (create when id is null, update otherwise).
 */
final readonly class RegisterOliveDeliveryCommand
{
    public function __construct(
        public ?string $id,
        public string $deliveredAt,
        public ProcessingMode $processingMode,
        public Commodity $commodity,
        public ?string $grainType,
        public ?string $farmerContactId,
        public ?string $cultivar,
        public ?string $originMunicipality,
        public ?string $cadastralReference,
        public ?string $sianOriginCode,
        public Certification $certification,
        public ?string $scheduledAt,
        public ?int $estimatedDurationMinutes,
        public ?string $hopperLine,
        public ?string $smsRecipientPhone,
        public ?string $notes,
        public ?array $weighing,
        public ?string $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public static function fromArray(array $data, ?string $userId = null): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            deliveredAt: (string) $data['delivered_at'],
            processingMode: ProcessingMode::from((string) $data['processing_mode']),
            commodity: Commodity::from((string) $data['commodity']),
            grainType: isset($data['grain_type']) ? (string) $data['grain_type'] : null,
            farmerContactId: isset($data['farmer_contact_id']) ? (string) $data['farmer_contact_id'] : null,
            cultivar: isset($data['cultivar']) ? (string) $data['cultivar'] : null,
            originMunicipality: isset($data['origin_municipality']) ? (string) $data['origin_municipality'] : null,
            cadastralReference: isset($data['cadastral_reference']) ? (string) $data['cadastral_reference'] : null,
            sianOriginCode: isset($data['sian_origin_code']) ? (string) $data['sian_origin_code'] : null,
            certification: Certification::from((string) $data['certification']),
            scheduledAt: isset($data['scheduled_at']) ? (string) $data['scheduled_at'] : null,
            estimatedDurationMinutes: isset($data['estimated_duration_minutes']) ? (int) $data['estimated_duration_minutes'] : null,
            hopperLine: isset($data['hopper_line']) ? (string) $data['hopper_line'] : null,
            smsRecipientPhone: isset($data['sms_recipient_phone']) ? (string) $data['sms_recipient_phone'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            weighing: isset($data['weighing']) ? (array) $data['weighing'] : null,
            userId: $userId,
        );
    }
}
