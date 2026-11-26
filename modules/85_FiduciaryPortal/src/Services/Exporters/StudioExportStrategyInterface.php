<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services\Exporters;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\FiduciaryPortal\Models\FiduciaryAccountTranscoding;
use Modules\GeneralLedger\Models\JournalEntry;

interface StudioExportStrategyInterface
{
    public function format(): StudioExportFormat;

    /**
     * @param  Collection<int, JournalEntry>  $entries
     * @param  array<string, FiduciaryAccountTranscoding>  $transcodingMap
     * @param  array<string, mixed>  $options
     * @return array{content: string, file_name: string, mime_type: string, rows_count: int}
     */
    public function generate(
        Tenant $tenant,
        Collection $entries,
        array $transcodingMap,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $currency,
        array $options = [],
    ): array;
}
