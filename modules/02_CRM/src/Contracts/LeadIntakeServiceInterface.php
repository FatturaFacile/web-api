<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Illuminate\Http\Request;
use Modules\CRM\DTOs\IntakeResult;
use Modules\CRM\Models\LeadSource;

interface LeadIntakeServiceInterface
{
    public function adapterFor(LeadSource $source): LeadPayloadAdapter;

    /**
     * Runs normalize -> dedup -> create/merge -> score -> route -> notify for a verified delivery.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \Illuminate\Validation\ValidationException when the payload carries no usable identity
     */
    public function ingest(LeadSource $source, array $payload): IntakeResult;

    /**
     * Records a behavioural event coming from a marketing tool for the lead identified by email/phone.
     *
     * @param  array<string, mixed>  $payload
     * @return array{lead_id: string, event_id: string, created: bool, score: int, rating: string}|null null when no lead matches
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ingestEvent(LeadSource $source, array $payload): ?array;

    public function verify(Request $request, LeadSource $source): bool;
}
