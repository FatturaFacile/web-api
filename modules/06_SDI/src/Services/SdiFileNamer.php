<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Hands out SDI file names, IT<fiscal id>_<progressive>.xml. SDI refuses a name it has already seen (error 00002), even
 * for a corrected invoice, so the progressive comes from a counter of the tenant that never goes back.
 */
final class SdiFileNamer
{
    /**
     * @return array{file_name: string, progressive: string}
     */
    public function next(Tenant $tenant): array
    {
        $counter = DB::transaction(function () use ($tenant): int {
            $current = (int) DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->value('sdi_file_counter');
            DB::table('tenants')->where('id', $tenant->id)->update(['sdi_file_counter' => $current + 1]);

            return $current + 1;
        });

        $progressive = str_pad(strtoupper(base_convert((string) $counter, 10, 36)), 5, '0', STR_PAD_LEFT);

        return [
            'file_name' => sprintf('IT%s_%s.xml', $this->fiscalIdentifier($tenant), $progressive),
            'progressive' => $progressive,
        ];
    }

    /**
     * The progressive inside an existing file name, used when the same file is transmitted again.
     */
    public function progressiveOf(string $fileName): string
    {
        return (string) preg_replace('/^.*_|\..*$/', '', $fileName);
    }

    private function fiscalIdentifier(Tenant $tenant): string
    {
        $vat = preg_replace('/\D/', '', (string) $tenant->vat_number);

        return $vat ?: strtoupper((string) $tenant->fiscal_code) ?: '99999999999';
    }
}
