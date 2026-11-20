<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Handlers;

use Modules\DigitalPreservation\Application\Queries\GetPreservationPackageDetailQuery;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationVault;

final readonly class GetPreservationPackageDetailHandler
{
    public function __construct(private PreservationVault $vault) {}

    /**
     * @return array{batch: PreservationBatch, pdv_xml: ?string, rdv_xml: ?string, timestamp: ?array{gen_time: ?string, provider: ?string, qualified: bool, token_base64: ?string}}
     */
    public function handle(GetPreservationPackageDetailQuery $query): array
    {
        $batch = PreservationBatch::with('items')->findOrFail($query->batchId);
        $token = $this->vault->get($batch->rfc3161_token_path);

        return [
            'batch' => $batch,
            'pdv_xml' => $this->vault->get($batch->pdv_xml_path),
            'rdv_xml' => $this->vault->get($batch->rdv_receipt_path),
            'timestamp' => $token === null ? null : [
                'gen_time' => $batch->rfc3161_gen_time?->toIso8601String(), 'provider' => $batch->rfc3161_provider,
                'qualified' => $batch->rfc3161_qualified, 'token_base64' => base64_encode($token),
            ],
        ];
    }
}
