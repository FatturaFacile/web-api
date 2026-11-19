<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Illuminate\Http\Request;
use Modules\CRM\Contracts\MetaLeadGraphClientInterface;
use Modules\CRM\Models\LeadSource;

/**
 * Meta (Facebook/Instagram) Lead Ads. Deliveries are signed with X-Hub-Signature-256 (app secret stored as the
 * source webhook secret). The expanded lead ({id, field_data: [{name, values}]}) is accepted directly, as
 * forwarded by Make/Zapier; a bare leadgen notification is expanded through the Graph client.
 */
final class MetaLeadAdsAdapter extends AbstractLeadPayloadAdapter
{
    public function __construct(
        private readonly MetaLeadGraphClientInterface $graphClient,
    ) {}

    public function verify(Request $request, LeadSource $source): bool
    {
        return $this->verifyBodySignature($request, $source, 'X-Hub-Signature-256', 'sha256')
            || parent::verify($request, $source);
    }

    protected function extractFields(array $payload, LeadSource $source): array
    {
        $lead = $this->resolveLead($payload, $source);
        $fieldData = $lead['field_data'] ?? null;

        return is_array($fieldData) ? $this->flattenFieldList($fieldData) : [];
    }

    protected function extractExternalId(array $payload): ?string
    {
        $id = $payload['leadgen_id'] ?? $payload['id'] ?? $this->leadgenIdFromNotification($payload);

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveLead(array $payload, LeadSource $source): array
    {
        if (isset($payload['field_data']) && is_array($payload['field_data'])) {
            return $payload;
        }

        $leadgenId = $payload['leadgen_id'] ?? $this->leadgenIdFromNotification($payload);
        if (! is_scalar($leadgenId) || (string) $leadgenId === '') {
            return [];
        }

        return $this->graphClient->fetchLead($source, (string) $leadgenId) ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function leadgenIdFromNotification(array $payload): ?string
    {
        $value = data_get($payload, 'entry.0.changes.0.value.leadgen_id');

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
