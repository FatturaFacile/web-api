<?php

declare(strict_types=1);

namespace Modules\Customs\Gateways;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Customs\Contracts\CustomsGateway;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\DTOs\RetrievedAssessment;
use Modules\Customs\DTOs\SubmissionResult;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Exceptions\CustomsGatewayException;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\Edec\EdecReceiptRequestBuilder;
use Modules\Customs\Services\Edec\EdecResponseParser;
use Modules\Customs\Services\Edec\EdecSchemaValidator;
use Modules\Customs\Services\Edec\EdecSoapTransport;

/**
 * The BAZG e-dec web services (EdecService v4, EdecReceiptService v3), built from the published schemas and service
 * contracts. Nothing is sent unless the installation opted in with a live switch, the endpoints and a client
 * certificate. It has never been run against a BAZG system.
 */
final class EdecGateway implements CustomsGateway
{
    /** BAZG refuses a list request on a longer period (error V12). */
    private const int MAX_IMPORT_LIST_DAYS = 10;

    public function __construct(
        private readonly EdecSoapTransport $transport,
        private readonly EdecReceiptRequestBuilder $requests,
        private readonly EdecResponseParser $responses,
        private readonly EdecSchemaValidator $validator,
    ) {}

    public function name(): string
    {
        return 'edec';
    }

    public function submit(Tenant $tenant, CustomsDeclaration $declaration, string $message): SubmissionResult
    {
        $endpoint = $this->requireLive('declaration_endpoint');

        if ($declaration->declaration_type === DeclarationType::Transit) {
            throw new CustomsGatewayException('e-dec does not carry transit declarations.');
        }

        $lastDay = (string) config('customs.edec.last_import_declaration_on');

        if ($declaration->declaration_type === DeclarationType::Import && $lastDay !== '' && now()->toDateString() > $lastDay) {
            throw new CustomsGatewayException("The last import declaration through e-dec was possible on {$lastDay}.");
        }

        $call = $this->transport->call($endpoint, 'goodsDeclarations', $message);

        return $this->responses->declaration($this->transport->bodyOf($call['envelope']));
    }

    public function listAssessments(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $endpoint = $this->requireLive('receipt_endpoint');
        $settings = $this->settingsFor($tenant);

        if ($from->diffInDays($to) > self::MAX_IMPORT_LIST_DAYS) {
            throw new CustomsGatewayException('e-dec lists at most '.self::MAX_IMPORT_LIST_DAYS.' days at a time.');
        }

        $references = [];

        foreach ([EdecReceiptRequestBuilder::ALL_IMPORT_DOCUMENTS, EdecReceiptRequestBuilder::EXPORT_DOCUMENTS] as $documentTypes) {
            $request = $this->requests->list($settings, $from, $to, $documentTypes);
            $this->assertRequest($request);

            $call = $this->transport->call($endpoint, 'receiptRequest', $request);
            $references = [...$references, ...$this->responses->receiptList($this->transport->bodyOf($call['envelope']))];
        }

        return $references;
    }

    public function fetchAssessment(Tenant $tenant, AssessmentReference $reference): RetrievedAssessment
    {
        $endpoint = $this->requireLive('receipt_endpoint');
        $request = $this->requests->single($this->settingsFor($tenant), $reference);
        $this->assertRequest($request);

        $call = $this->transport->call($endpoint, 'receiptRequest', $request);
        $xml = $this->transport->bodyOf($call['envelope']);
        $this->responses->assertDocument($xml);

        return new RetrievedAssessment(xml: $xml, signedEnvelope: $call['envelope'], pdf: $call['pdf']);
    }

    /**
     * The guard of the real channel: every piece must be given on purpose, and no default points to BAZG.
     *
     * @throws CustomsGatewayException when anything is missing
     */
    private function requireLive(string $endpointKey): string
    {
        if (config('customs.edec.live_transmission') !== true) {
            throw new CustomsGatewayException('Live transmission to BAZG is switched off: set CUSTOMS_EDEC_LIVE_TRANSMISSION on an installation registered with BAZG.');
        }

        $endpoint = (string) config("customs.edec.{$endpointKey}");
        $certificate = (string) config('customs.edec.client_certificate');
        $key = (string) config('customs.edec.client_key');

        if (! str_starts_with($endpoint, 'https://')) {
            throw new CustomsGatewayException('The e-dec endpoint is not configured.');
        }

        if (! is_file($certificate) || ! is_file($key)) {
            throw new CustomsGatewayException('The client certificate issued for BAZG is not configured.');
        }

        return $endpoint;
    }

    private function settingsFor(Tenant $tenant): CustomsSettings
    {
        $settings = CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if ($settings === null || (string) $settings->trader_identification_number === '' || $settings->receipt_role === null) {
            throw new CustomsGatewayException('The customs settings lack the trader identification number or the role of the requestor.');
        }

        return $settings;
    }

    private function assertRequest(string $request): void
    {
        $errors = $this->validator->errors($request, EdecSchemaValidator::RECEIPT_REQUEST);

        if ($errors !== []) {
            throw new CustomsGatewayException('The request does not match the BAZG schema: '.$errors[0]);
        }
    }
}
