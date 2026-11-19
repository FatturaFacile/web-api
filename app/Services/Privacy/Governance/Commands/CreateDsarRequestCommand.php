<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

final readonly class CreateDsarRequestCommand
{
    /**
     * @param  array{subject_type: string, subject_entity_id?: string|null, subject_email: string, subject_full_name: string, request_type: string, received_at?: string|null, audit_notes?: string|null}  $data
     */
    public function __construct(public string $tenantId, public array $data) {}
}
