<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Requests\Privacy\RecordConsentRequest;
use App\Http\Resources\Privacy\ConsentLedgerEntryResource;
use App\Models\Privacy\ConsentLedgerEntry;
use App\Services\Privacy\Governance\ConsentLedgerService;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tamper-evident consent ledger (GDPR art. 7(1)). Append only: there is no update or delete endpoint.
 */
final class ConsentLedgerController extends PrivacyGovernanceController
{
    public function index(Request $request, ConsentLedgerService $ledger): JsonResponse
    {
        $filters = $request->validate([
            'subject_identifier' => ['nullable', 'string', 'max:255'],
            'subject_entity_id' => ['nullable', 'string', 'max:64'],
            'purpose_code' => ['nullable', 'string', 'max:60'],
            'consent_granted' => ['nullable', 'boolean'],
            'policy_version' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $tenantId = $this->tenantId($request);

        $page = ConsentLedgerEntry::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)
            ->when(isset($filters['subject_identifier']), static fn ($q) => $q->where('subject_identifier_hash', ConsentLedgerService::identifierHash($filters['subject_identifier'])))
            ->when(isset($filters['subject_entity_id']), static fn ($q) => $q->where('subject_entity_id', $filters['subject_entity_id']))
            ->when(isset($filters['purpose_code']), static fn ($q) => $q->where('purpose_code', $filters['purpose_code']))
            ->when(isset($filters['consent_granted']), static fn ($q) => $q->where('consent_granted', (bool) $filters['consent_granted']))
            ->when(isset($filters['policy_version']), static fn ($q) => $q->where('policy_version', $filters['policy_version']))
            ->orderByDesc('sequence')->paginate((int) ($filters['per_page'] ?? 50));

        $broken = $ledger->firstBrokenSequence($tenantId);

        return ConsentLedgerEntryResource::collection($page)->additional(['meta_chain' => ['is_intact' => $broken === null, 'first_broken_sequence' => $broken]])->response();
    }

    public function store(RecordConsentRequest $request, ConsentLedgerService $ledger): JsonResponse
    {
        $data = $request->validated();
        $data['ip_address'] ??= $request->ip();
        $data['user_agent'] ??= $request->userAgent();
        $entry = $ledger->record($this->tenantId($request), $data);

        return (new ConsentLedgerEntryResource($entry->refresh()))->response()->setStatusCode(201);
    }
}
