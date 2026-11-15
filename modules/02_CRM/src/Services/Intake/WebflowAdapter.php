<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Illuminate\Http\Request;
use Modules\CRM\Models\LeadSource;

/**
 * Webflow form_submission webhooks: {triggerType, payload: {id, name, data: {...}}}.
 * Accepts Webflow's native signature (x-webflow-timestamp in ms, x-webflow-signature = hmac("{ts}:{body}"))
 * as well as the generic X-Signature / X-Timestamp scheme.
 */
final class WebflowAdapter extends AbstractLeadPayloadAdapter
{
    public function verify(Request $request, LeadSource $source): bool
    {
        return $this->verifyWebflowSignature($request, $source) || parent::verify($request, $source);
    }

    protected function extractFields(array $payload, LeadSource $source): array
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $data = $inner['data'] ?? [];

        return is_array($data) ? $this->flattenFieldList($data) : [];
    }

    protected function extractExternalId(array $payload): ?string
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $id = $inner['id'] ?? $inner['_id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private function verifyWebflowSignature(Request $request, LeadSource $source): bool
    {
        $signature = strtolower((string) $request->header('x-webflow-signature', ''));
        $timestamp = (string) $request->header('x-webflow-timestamp', '');
        $secret = (string) $source->webhook_secret;

        if ($signature === '' || $secret === '' || ! $this->isFreshTimestamp($timestamp, 1000)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.':'.$request->getContent(), $secret), $signature);
    }
}
