<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Illuminate\Http\Request;
use Modules\CRM\Models\LeadSource;

/**
 * LinkedIn Lead Gen Forms: X-LI-Signature is an HMAC-SHA256 of the raw body (optionally "hmacsha256=" prefixed).
 * Fields come as a map ({firstName, emailAddress, ...}) or as answers [{name|question, value|answer}].
 */
final class LinkedInLeadGenAdapter extends AbstractLeadPayloadAdapter
{
    public function verify(Request $request, LeadSource $source): bool
    {
        return $this->verifyBodySignature($request, $source, 'X-LI-Signature', 'hmacsha256')
            || parent::verify($request, $source);
    }

    protected function extractFields(array $payload, LeadSource $source): array
    {
        foreach (['fields', 'answers', 'formResponse.answers', 'lead'] as $path) {
            $value = data_get($payload, $path);
            if (is_array($value)) {
                return $this->flattenFieldList($value);
            }
        }

        unset($payload['leadId'], $payload['lead_id'], $payload['id']);

        return $payload;
    }

    protected function extractExternalId(array $payload): ?string
    {
        $id = $payload['leadId'] ?? $payload['lead_id'] ?? $payload['id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
