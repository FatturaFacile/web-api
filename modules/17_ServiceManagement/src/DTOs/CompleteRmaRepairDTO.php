<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class CompleteRmaRepairDTO
{
    /**
     * @param  list<array{old_component_id: string, new_component_serial: string, component_product_id?: string|null}>  $replacedComponents
     */
    public function __construct(
        public array $replacedComponents = [],
        public ?string $installedFirmwareVersion = null,
        public ?string $testNotes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            replacedComponents: array_values($data['replaced_components'] ?? []),
            installedFirmwareVersion: $data['installed_firmware_version'] ?? null,
            testNotes: $data['test_notes'] ?? null,
        );
    }
}
