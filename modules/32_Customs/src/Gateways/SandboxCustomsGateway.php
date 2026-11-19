<?php

declare(strict_types=1);

namespace Modules\Customs\Gateways;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Customs\Contracts\CustomsGateway;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\DTOs\SubmissionResult;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Support\Mrn;

/**
 * Simulator behind the Passar and AIDA channels: it accepts a declaration that holds a payload and answers with a
 * well-formed but simulated MRN. No transport to BAZG or ADM exists, so nothing leaves the application.
 */
abstract class SandboxCustomsGateway implements CustomsGateway
{
    /**
     * Country letters of the simulated MRN.
     */
    abstract protected function mrnCountry(): string;

    public function submit(Tenant $tenant, CustomsDeclaration $declaration, string $message): SubmissionResult
    {
        if (trim($message) === '') {
            return new SubmissionResult(isAccepted: false, response: $message, rejectionReason: 'The payload is empty.');
        }

        return new SubmissionResult(isAccepted: true, response: $message, mrn: Mrn::simulated($this->mrnCountry()), version: 1);
    }

    public function listAssessments(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function fetchAssessment(Tenant $tenant, AssessmentReference $reference): RetrievedAssessment
    {
        throw new CustomsGatewayException("The {$this->name()} sandbox holds no assessment decisions: upload the decision file instead.");
    }
}
