<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Modules\SDI\Models\SdiConfiguration;

/**
 * Single per-tenant source of SDI transmission progressives. SDI refuses a file name it has already seen (00002), so
 * every sender (first transmission and resubmission) draws from this one sequence. It keeps the tenant counter and the
 * SDI configuration counter aligned, and never goes back below the highest value either of them reached.
 */
final class SdiProgressiveSequence
{
    public const int Maximum = 60466175; // base36 ZZZZZ

    public function next(Tenant $tenant): int
    {
        return DB::transaction(function () use ($tenant): int {
            $tenantCounter = (int) DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->value('sdi_file_counter');

            $configuration = SdiConfiguration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->first()
                ?? SdiConfiguration::withoutGlobalScopes()->create(['tenant_id' => $tenant->id]);

            $next = max($tenantCounter, (int) $configuration->progressivo_invio_counter) + 1;

            if ($next > self::Maximum) {
                throw new \OverflowException('ProgressivoInvio sequence exhausted.');
            }

            DB::table('tenants')->where('id', $tenant->id)->update(['sdi_file_counter' => $next]);
            $configuration->forceFill(['progressivo_invio_counter' => $next])->save();

            return $next;
        });
    }

    public function encode(int $counter): string
    {
        return str_pad(strtoupper(base_convert((string) $counter, 10, 36)), 5, '0', STR_PAD_LEFT);
    }
}
