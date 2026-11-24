<?php

declare(strict_types=1);

namespace Modules\Customs\Contracts;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\DTOs\SubmissionResult;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Models\CustomsDeclaration;

interface CustomsGateway
{
    /**
     * Stable identifier stored on declarations, messages and decisions.
     */
    public function name(): string;

    /**
     * Hand a declaration message over to customs.
     *
     * @throws CustomsGatewayException when the channel refuses, cannot process it or is not configured
     */
    public function submit(Tenant $tenant, CustomsDeclaration $declaration, string $message): SubmissionResult;

    /**
     * The assessment decisions issued in the period.
     *
     * @return list<AssessmentReference>
     *
     * @throws CustomsGatewayException when the channel cannot be reached or is not configured
     */
    public function listAssessments(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * Download one assessment decision.
     *
     * @throws CustomsGatewayException when the channel cannot be reached or refuses the document
     */
    public function fetchAssessment(Tenant $tenant, AssessmentReference $reference): RetrievedAssessment;
}
