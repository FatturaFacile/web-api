<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Services\Privacy\Governance\DsarService;

final readonly class CreateDsarRequestHandler
{
    public function __construct(private DsarService $dsars) {}

    public function handle(CreateDsarRequestCommand $command): PrivacySubjectRequestDsar
    {
        return $this->dsars->create($command->tenantId, $command->data);
    }
}
