<?php

declare(strict_types=1);

namespace Modules\Customs\Gateways;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Customs\Contracts\CustomsGateway;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\DTOs\SubmissionResult;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Models\CustomsDeclaration;

/**
 * Offline channel for development and demos: nothing reaches customs. The reference it gives starts with LOCAL so that
 * it can never be mistaken for a real MRN.
 */
final class LocalCustomsGateway implements CustomsGateway
{
    public function name(): string
    {
        return 'local';
    }

    public function submit(Tenant $tenant, CustomsDeclaration $declaration, string $message): SubmissionResult
    {
        return new SubmissionResult(
            isAccepted: true,
            response: $message,
            mrn: 'LOCAL'.strtoupper(Str::random(16)),
            version: 1,
        );
    }

    public function listAssessments(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function fetchAssessment(Tenant $tenant, AssessmentReference $reference): RetrievedAssessment
    {
        throw new CustomsGatewayException('The local channel holds no assessment decisions.');
    }
}
