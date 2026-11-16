<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Modules\CRM\Models\LeadSource;

/**
 * Generic HTML/JS web form: flat JSON or form-encoded fields, optionally nested under "fields".
 */
class WebFormAdapter extends AbstractLeadPayloadAdapter
{
    protected function extractFields(array $payload, LeadSource $source): array
    {
        $fields = $payload['fields'] ?? null;

        if (is_array($fields)) {
            return $this->flattenFieldList($fields);
        }

        unset($payload['external_id'], $payload['submission_id']);

        return $payload;
    }

    protected function extractExternalId(array $payload): ?string
    {
        foreach (['external_id', 'submission_id', 'entry_id', 'id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
