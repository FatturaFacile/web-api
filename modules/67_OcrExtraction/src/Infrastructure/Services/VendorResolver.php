<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\OcrExtraction\Domain\ValueObjects\ExtractedPayloadDto;

/**
 * Finds the vendor (contact) of the tenant an extracted document comes from, by the strongest identifier first: Swiss UID
 * (CHE), VAT number (IT...), then IBAN or QR-IBAN. Spaces, dots and dashes in the stored values do not matter.
 */
final class VendorResolver
{
    public function resolve(string $tenantId, ExtractedPayloadDto $payload): ?string
    {
        $vendor = $payload->vendor;

        $uid = $this->compact($vendor['uid'] ?? null);

        if ($uid !== null && ($id = $this->find($tenantId, ['uid_number'], [$uid])) !== null) {
            return $id;
        }

        $vat = $this->compact($vendor['vat_number'] ?? null);

        if ($vat !== null) {
            $variants = ctype_digit($vat) ? [$vat, 'IT'.$vat] : [$vat, preg_replace('/^[A-Z]{2}/', '', $vat) ?? $vat];

            if (($id = $this->find($tenantId, ['vat_number'], $variants)) !== null) {
                return $id;
            }
        }

        $ibans = array_values(array_filter([$this->compact($vendor['iban'] ?? null), $this->compact($vendor['qr_iban'] ?? null)]));

        return $ibans === [] ? null : $this->find($tenantId, ['iban', 'qr_iban'], $ibans);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $values  compacted (upper case, no space, dot or dash)
     */
    private function find(string $tenantId, array $columns, array $values): ?string
    {
        $query = DB::table('contacts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where(function ($group) use ($columns, $values): void {
            foreach ($columns as $column) {
                $group->orWhereIn(DB::raw("REPLACE(REPLACE(REPLACE(UPPER({$column}), ' ', ''), '.', ''), '-', '')"), $values);
            }
        });

        $id = $query->orderBy('created_at')->value('id');

        return is_string($id) ? $id : null;
    }

    private function compact(?string $value): ?string
    {
        $value = $value === null ? '' : strtoupper(preg_replace('/[\s.\-]/', '', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
