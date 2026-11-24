<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\DB;

/**
 * Finds the contact of the tenant an extracted customer or supplier most likely is: an exact email or VAT number is almost
 * certain, an exact company name is probable. Anything weaker is not guessed: the reviewer picks the contact.
 */
final class ContactMatcher
{
    /**
     * @return array{id: string, confidence: float, reason: string}|null
     */
    public function find(string $tenantId, ?string $email, ?string $vatNumber, ?string $companyName): ?array
    {
        $base = fn () => DB::table('contacts')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        if ($email !== null && $email !== '') {
            $id = $base()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->value('id');

            if ($id !== null) {
                return ['id' => (string) $id, 'confidence' => 1.0, 'reason' => 'email'];
            }
        }

        if ($vatNumber !== null && $vatNumber !== '') {
            $normalized = strtoupper((string) preg_replace('/[\s.\-]/', '', $vatNumber));
            $id = $base()->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(COALESCE(vat_number, ''), ' ', ''), '.', ''), '-', '')) = ?", [$normalized])->value('id');

            if ($id !== null) {
                return ['id' => (string) $id, 'confidence' => 0.98, 'reason' => 'vat_number'];
            }
        }

        if ($companyName !== null && trim($companyName) !== '') {
            $id = $base()->whereRaw('LOWER(company_name) = ?', [mb_strtolower(trim($companyName))])->value('id');

            if ($id !== null) {
                return ['id' => (string) $id, 'confidence' => 0.85, 'reason' => 'company_name'];
            }
        }

        return null;
    }
}
