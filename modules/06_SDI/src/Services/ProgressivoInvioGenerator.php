<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Tenant;
use Modules\SDI\Models\SdiConfiguration;

/**
 * Atomic ProgressivoInvio generator: base-36 alphanumeric, five characters, 00001 to ZZZZZ. The counter lives on the
 * per-tenant SDI configuration and never goes back, because SDI refuses a file name it has already seen (00002).
 */
final class ProgressivoInvioGenerator
{
    public const int Maximum = 60466175; // base36 ZZZZZ

    /**
     * @return array{progressive: string, file_name: string}
     */
    public function next(Tenant $tenant, string $extension = 'xml'): array
    {
        $sequence = new SdiProgressiveSequence;
        $counter = $sequence->next($tenant);
        $configuration = SdiConfiguration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $progressive = $sequence->encode($counter);
        $country = $configuration->transmitter_country_code ?: 'IT';
        $code = $configuration->transmitter_fiscal_code ?: (preg_replace('/\D/', '', (string) $tenant->vat_number) ?: strtoupper((string) $tenant->fiscal_code));

        return ['progressive' => $progressive, 'file_name' => sprintf('%s%s_%s.%s', $country, $code, $progressive, $extension)];
    }

    public function encode(int $counter): string
    {
        return str_pad(strtoupper(base_convert((string) $counter, 10, 36)), 5, '0', STR_PAD_LEFT);
    }
}
