<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Modules\CRM\Models\LeadSource;

/**
 * WordPress form plugins (Contact Form 7, WPForms, Gravity Forms) posting through a webhook add-on:
 * fields come either as a map or as a list of {name|label, value}; the entry id is the idempotency key.
 */
final class WordPressAdapter extends WebFormAdapter
{
    protected function extractFields(array $payload, LeadSource $source): array
    {
        foreach (['fields', 'form_data', 'posted_data', 'entry'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->flattenFieldList($payload[$key]);
            }
        }

        unset($payload['form_id'], $payload['entry_id'], $payload['external_id']);

        return $payload;
    }

    protected function extractExternalId(array $payload): ?string
    {
        $formId = $payload['form_id'] ?? null;
        $entryId = $payload['entry_id'] ?? $payload['submission_id'] ?? $payload['external_id'] ?? null;

        if (! is_scalar($entryId) || (string) $entryId === '') {
            return null;
        }

        return is_scalar($formId) && (string) $formId !== '' ? "{$formId}:{$entryId}" : (string) $entryId;
    }
}
