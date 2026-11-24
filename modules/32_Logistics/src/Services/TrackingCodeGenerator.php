<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesDeliveryNote;

/**
 * Internal tracking code of a DDT ("FF" + year + 8 unambiguous characters), unique within the tenant.
 */
final class TrackingCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function next(): string
    {
        do {
            $code = 'FF'.now()->format('y').$this->randomSuffix(8);
        } while (SalesDeliveryNote::query()->where('tracking_code', $code)->exists());

        return $code;
    }

    private function randomSuffix(int $length): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return Str::upper($out);
    }
}
